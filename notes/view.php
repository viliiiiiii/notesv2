<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$me   = current_user();
$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$ownerId         = (int)($note['user_id'] ?? 0);
$canEdit         = notes_can_edit($note);
$canShare        = notes_can_share($note);
$photos          = notes_fetch_photos($id);
$currentShareIds = $canShare ? array_map('intval', notes_get_share_user_ids($id) ?: []) : [];
$shareDetails    = notes_get_share_details($id);
$commentsEnabled = notes_comments_table_exists();
$commentThreads  = $commentsEnabled ? notes_fetch_comment_threads($id) : [];
$commentCount    = $commentsEnabled ? notes_comment_count($id) : 0;
$meta            = notes_fetch_page_meta($id);
$tags            = notes_fetch_note_tags($id);
$blocks          = notes_fetch_blocks($id);

$shareOptions = [];
if ($canShare) {
    $users = notes_all_users();
    $seen  = [];
    foreach ($users as $user) {
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0 || isset($seen[$uid])) {
            continue;
        }
        $label = trim((string)($user['email'] ?? ''));
        if ($label === '') {
            $label = 'User #' . $uid;
        }
        $shareOptions[] = [
            'id'       => $uid,
            'label'    => $label,
            'is_owner' => $uid === $ownerId,
        ];
        $seen[$uid] = true;
    }
}

if (!$blocks) {
    $fallbackBody = trim((string)($note['body'] ?? ''));
    if ($fallbackBody !== '') {
        $fallback = notes_normalize_block([
            'type' => 'paragraph',
            'text' => $fallbackBody,
        ], 1);
        if ($fallback) {
            $blocks = [$fallback];
        }
    }
}

$errors = [];

$decorateComments = static function (array &$items) use (&$decorateComments, $note): void {
    foreach ($items as &$item) {
        $item['can_delete'] = notes_comment_can_delete($item, $note);
        if (!empty($item['children'])) {
            $decorateComments($item['children']);
        }
    }
    unset($item);
};

if ($commentsEnabled && $commentThreads) {
    $decorateComments($commentThreads);
}

if (is_post()) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (isset($_POST['toggle_block'])) {
        $response = ['ok' => false, 'message' => ''];
        if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
            $response['message'] = 'Invalid CSRF token.';
        } elseif (!$canEdit) {
            $response['message'] = 'You cannot modify this note.';
        } else {
            $blockUid = trim((string)$_POST['toggle_block']);
            $checked  = !empty($_POST['checked']);
            if ($blockUid === '') {
                $response['message'] = 'Missing block identifier.';
            } else {
                $response['ok'] = notes_toggle_block_checkbox($id, $blockUid, $checked);
                if (!$response['ok']) {
                    $response['message'] = 'Unable to update checkbox.';
                }
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($response['ok']) {
            redirect_with_message('view.php?id=' . $id, 'Checklist updated.', 'success');
        } else {
            $errors[] = $response['message'] ?: 'Unable to update checkbox.';
        }
    }

    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if ($canShare && isset($_POST['update_shares'])) {
            $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
            try {
                $result = notes_apply_shares($id, $selected, $note, true);
                $currentShareIds = $result['after'];
                $shareDetails    = notes_get_share_details($id);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok'       => true,
                        'shares'   => $shareDetails,
                        'selected' => $currentShareIds,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                redirect_with_message('view.php?id=' . $id, 'Sharing updated.', 'success');
            } catch (Throwable $e) {
                error_log('notes_apply_shares failed: ' . $e->getMessage());
                if ($isAjax) {
                    header('Content-Type: application/json', true, 500);
                    echo json_encode([
                        'ok'      => false,
                        'message' => 'Failed to update shares.',
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $currentShareIds = $selected;
                $errors[] = 'Failed to update shares.';
            }
        }

        if (isset($_POST['add_comment']) && $commentsEnabled) {
            $body     = trim((string)($_POST['body'] ?? ''));
            $parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

            if ($body === '') {
                $errors[] = 'Comment cannot be empty.';
            } else {
                try {
                    $authorId  = (int)($me['id'] ?? 0);
                    $commentId = notes_comment_insert($id, $authorId, $body, $parentId ?: null);
                    log_event('note.comment.create', 'note', $id, ['comment_id' => $commentId]);

                    $ownerId    = (int)($note['user_id'] ?? 0);
                    $sharedIds  = array_map('intval', notes_get_share_user_ids($id) ?: []);
                    $recipients = array_unique(array_filter(array_merge([$ownerId], $sharedIds)));
                    $recipients = array_values(array_diff($recipients, [$authorId]));

                    if ($recipients) {
                        $titleText = trim((string)($note['title'] ?? 'Untitled'));
                        $titleMsg  = "New reply on “{$titleText}”";
                        $excerpt   = mb_substr($body, 0, 140);
                        $link      = "/notes/view.php?id={$id}#comment-{$commentId}";
                        $payload   = ['note_id' => (int)$id, 'comment_id' => (int)$commentId];

                        notify_users($recipients, 'note.comment', $titleMsg, $excerpt, $link, $payload);
                    }

                    redirect_with_message('view.php?id=' . $id . '#comment-' . $commentId, 'Reply posted.', 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Failed to save comment.';
                }
            }
        }

        if (isset($_POST['delete_comment']) && $commentsEnabled) {
            $commentId = (int)$_POST['delete_comment'];
            $comment   = notes_comment_fetch($commentId);
            if (!$comment || (int)($comment['note_id'] ?? 0) !== $id) {
                $errors[] = 'Comment not found.';
            } elseif (!notes_comment_can_delete($comment, $note)) {
                $errors[] = 'You cannot remove this comment.';
            } else {
                notes_comment_delete($commentId);
                log_event('note.comment.delete', 'note', $id, ['comment_id' => $commentId]);
                redirect_with_message('view.php?id=' . $id . '#comments', 'Comment removed.', 'success');
            }
        }
    }
}

if ($commentsEnabled) {
    $commentThreads = notes_fetch_comment_threads($id);
    $commentCount   = notes_comment_count($id);
    if ($commentThreads) {
        $decorateComments($commentThreads);
    }
}

$shareConfig = $canShare ? [
    'selected' => $currentShareIds,
    'owner'    => $ownerId,
    'options'  => $shareOptions,
] : null;

$properties       = $meta['properties'] ?? notes_default_properties();
$propertyLabels   = notes_property_labels();
$statusSlug       = $meta['status'] ?? NOTES_DEFAULT_STATUS;
$statusLabel      = notes_status_label($statusSlug);
$statusBadgeClass = notes_status_badge_class($statusSlug);

$noteDate = $note['note_date'] ?? '';
$noteDateFormatted = $noteDate;
if ($noteDate) {
    try {
        $noteDateFormatted = (new DateTimeImmutable($noteDate))->format('M j, Y');
    } catch (Throwable $e) {
        $noteDateFormatted = $noteDate;
    }
}

$ownerId    = (int)($note['user_id'] ?? 0);
$ownerLabel = 'Unknown';
if ($ownerId > 0) {
    $ownerMap = notes_fetch_users_map([$ownerId]);
    if (isset($ownerMap[$ownerId])) {
        $ownerLabel = $ownerMap[$ownerId];
    } elseif ($ownerId === (int)($me['id'] ?? 0)) {
        $ownerLabel = 'You';
    } else {
        $ownerLabel = 'User #' . $ownerId;
    }
} else {
    $ownerLabel = 'Unknown';
}

$csrfToken = csrf_token();
$title     = 'View Note';
$shareConfigAttr = $shareConfig
    ? htmlspecialchars(json_encode($shareConfig, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
    : '';
include __DIR__ . '/../includes/header.php';
?>
<section class="note-page note-page--view" data-note-page data-note-id="<?= (int)$id; ?>" data-csrf="<?= sanitize($csrfToken); ?>" data-can-share="<?= $canShare ? '1' : '0'; ?>" data-share-config="<?= $shareConfigAttr; ?>">
  <header class="note-hero<?= $meta['cover_url'] ? ' has-cover' : ''; ?>">
    <div class="note-hero__cover"<?= $meta['cover_url'] ? ' style="background-image:url(' . sanitize($meta['cover_url']) . ');"' : ''; ?>></div>
    <div class="note-hero__inner">
      <div class="note-hero__top">
        <div class="note-hero__identity">
          <div class="note-icon" aria-hidden="true">
            <?= $meta['icon'] ? sanitize($meta['icon']) : '🗒️'; ?>
          </div>
          <div>
            <h1><?= sanitize($note['title'] ?: 'Untitled'); ?></h1>
            <div class="note-meta">
              <span class="badge <?= sanitize($statusBadgeClass); ?>"><?= sanitize($statusLabel); ?></span>
              <span class="note-meta__date" title="<?= sanitize($note['note_date'] ?? ''); ?>"><?= sanitize($noteDateFormatted); ?></span>
              <?php if ($commentCount): ?>
                <span class="note-meta__chip">💬 <?= (int)$commentCount; ?></span>
              <?php endif; ?>
              <span class="note-meta__shares" data-share-list>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="badge badge--muted"><?= sanitize($share['label']); ?></span>
                <?php endforeach; ?>
              </span>
            </div>
            <?php if ($tags): ?>
              <div class="note-tags">
                <?php foreach ($tags as $tag): ?>
                  <span class="note-tag" style="--tag-color: <?= sanitize($tag['color'] ?? notes_random_tag_color()); ?>;">
                    <?= sanitize($tag['label']); ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="note-hero__actions">
          <a class="btn" href="index.php">Back to notes</a>
          <?php if ($canShare): ?>
            <button class="btn secondary" type="button" data-share-open>Share</button>
          <?php endif; ?>
          <?php if ($canEdit): ?>
            <a class="btn primary" href="edit.php?id=<?= (int)$note['id']; ?>">Edit note</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($errors): ?>
        <div class="flash flash-error" role="alert"><?= sanitize(implode(' ', $errors)); ?></div>
      <?php endif; ?>
    </div>
  </header>

  <div class="note-layout">
    <article class="note-content card card--surface">
      <?php if ($blocks): ?>
        <div class="note-blocks">
          <?php foreach ($blocks as $block):
            $type = $block['type'] ?? 'paragraph';
            $text = (string)($block['text'] ?? '');
            $items = is_array($block['items'] ?? null) ? $block['items'] : [];
            $checked = !empty($block['checked']);
            $uid = $block['uid'] ?? '';
            $calloutColor = $block['color'] ?? null;
            $calloutIcon  = $block['icon'] ?? null;
          ?>
          <div class="note-block note-block--<?= sanitize($type); ?>">
            <?php switch ($type) {
              case 'heading1': ?>
                <h2 class="note-block__heading note-block__heading--h1"><?= nl2br(sanitize($text)); ?></h2>
              <?php break;
              case 'heading2': ?>
                <h3 class="note-block__heading note-block__heading--h2"><?= nl2br(sanitize($text)); ?></h3>
              <?php break;
              case 'heading3': ?>
                <h4 class="note-block__heading note-block__heading--h3"><?= nl2br(sanitize($text)); ?></h4>
              <?php break;
              case 'todo': ?>
                <label class="note-block__todo">
                  <input type="checkbox" value="1" data-block-toggle="<?= sanitize($uid); ?>" <?= $checked ? 'checked' : ''; ?> <?= $canEdit ? '' : 'disabled'; ?>>
                  <span><?= nl2br(sanitize($text)); ?></span>
                </label>
              <?php break;
              case 'bulleted': ?>
                <ul class="note-block__list note-block__list--bulleted">
                  <?php foreach ($items as $item): ?>
                    <li><?= nl2br(sanitize((string)$item)); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php break;
              case 'numbered': ?>
                <ol class="note-block__list note-block__list--numbered">
                  <?php foreach ($items as $item): ?>
                    <li><?= nl2br(sanitize((string)$item)); ?></li>
                  <?php endforeach; ?>
                </ol>
              <?php break;
              case 'quote': ?>
                <blockquote class="note-block__quote"><?= nl2br(sanitize($text)); ?></blockquote>
              <?php break;
              case 'callout': ?>
                <div class="note-block__callout"<?= $calloutColor ? ' style="--callout-accent:' . sanitize($calloutColor) . ';"' : ''; ?>>
                  <div class="note-block__callout-icon" aria-hidden="true"><?= $calloutIcon ? sanitize($calloutIcon) : '💡'; ?></div>
                  <div><?= nl2br(sanitize($text)); ?></div>
                </div>
              <?php break;
              case 'divider': ?>
                <div class="note-block__divider" role="presentation"></div>
              <?php break;
              default: ?>
                <p><?= nl2br(sanitize($text)); ?></p>
              <?php break;
            } ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">No content yet.</p>
      <?php endif; ?>
    </article>

    <aside class="note-sidebar">
      <section class="note-panel card card--surface">
        <header class="note-panel__header">
          <h2>Properties</h2>
        </header>
        <dl class="note-properties">
          <?php foreach ($propertyLabels as $key => $label):
            $value = $properties[$key] ?? '';
            if ($key === 'due_date' && $value) {
                try {
                    $dt = new DateTimeImmutable($value);
                    $formatted = $dt->format('M j, Y');
                    if ($dt < new DateTimeImmutable('today')) {
                        $value = '<span class="is-overdue">' . sanitize($formatted) . '</span>';
                    } else {
                        $value = sanitize($formatted);
                    }
                } catch (Throwable $e) {
                    $value = sanitize($value);
                }
            } elseif ($key === 'priority' && $value !== '') {
                $badge = notes_priority_badge_class($value);
                $value = '<span class="badge ' . sanitize($badge) . '">' . sanitize($value) . '</span>';
            } else {
                $value = $value !== '' ? sanitize((string)$value) : '<span class="muted">—</span>';
            }
          ?>
          <div class="note-properties__item">
            <dt><?= sanitize($label); ?></dt>
            <dd><?= $value; ?></dd>
          </div>
          <?php endforeach; ?>
        </dl>
      </section>

      <section class="note-panel card card--surface">
        <header class="note-panel__header">
          <h2>Details</h2>
        </header>
        <div class="note-panel__body">
          <div class="note-detail-row">
            <span class="note-detail-label">Created</span>
            <span><?= sanitize($noteDateFormatted); ?></span>
          </div>
          <div class="note-detail-row">
            <span class="note-detail-label">Owner</span>
            <span><?= sanitize($ownerLabel); ?></span>
          </div>
          <div class="note-detail-row">
            <span class="note-detail-label">Shared with</span>
            <div class="note-detail-value" data-share-summary data-empty-text="Private">
              <?php if ($shareDetails): ?>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="badge badge--muted"><?= sanitize($share['label']); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="muted" data-share-empty>Private</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="note-panel card card--surface">
        <header class="note-panel__header">
          <h2>Attachments</h2>
          <?php if (array_filter($photos)): ?>
            <button class="btn small" type="button" id="openAllNotePhotos">View larger</button>
          <?php endif; ?>
        </header>
        <div class="note-panel__body">
          <div class="note-photos" id="noteViewPhotoGrid">
            <?php for ($i = 1; $i <= 3; $i++): $p = $photos[$i] ?? null; ?>
              <?php if ($p): ?>
                <a href="<?= sanitize($p['url']); ?>" class="note-photo-thumb js-zoom" target="_blank" rel="noopener">
                  <img src="<?= sanitize($p['url']); ?>" alt="Note photo <?= $i; ?>" loading="lazy" decoding="async">
                </a>
              <?php else: ?>
                <div class="note-photo-empty muted">Slot <?= $i; ?></div>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
      </section>
    </aside>
  </div>

  <section class="note-discussion card card--surface" id="comments">
    <header class="note-panel__header">
      <div>
        <h2>Discussion</h2>
        <p class="muted">Collaborate with teammates and keep decisions in context.</p>
      </div>
      <span class="badge badge--muted"><?= (int)$commentCount; ?> replies</span>
    </header>
    <div class="note-discussion__body">
      <?php if (!$commentsEnabled): ?>
        <p class="muted">Commenting is disabled because the note_comments table was not detected.</p>
      <?php else: ?>
        <?php if (!$commentThreads): ?>
          <p class="muted">No replies yet.</p>
        <?php else: ?>
          <div class="note-comments">
            <?php
            $renderComment = static function (array $comment, callable $renderComment) use ($csrfToken) {
                ?>
                <article class="note-comment" id="comment-<?= (int)$comment['id']; ?>">
                  <header class="note-comment__header">
                    <div>
                      <strong><?= sanitize($comment['author_label']); ?></strong>
                      <span class="note-comment__timestamp"><?= sanitize(substr((string)($comment['created_at'] ?? ''), 0, 16)); ?></span>
                    </div>
                    <?php if (!empty($comment['can_delete'])): ?>
                      <form method="post" class="note-comment__delete" onsubmit="return confirm('Delete this reply?');">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                        <button class="btn small ghost" type="submit" name="delete_comment" value="<?= (int)$comment['id']; ?>">Delete</button>
                      </form>
                    <?php endif; ?>
                  </header>
                  <div class="note-comment__body"><?= nl2br(sanitize($comment['body'] ?? '')); ?></div>
                  <footer class="note-comment__footer">
                    <details>
                      <summary>Reply</summary>
                      <form method="post" class="note-comment-form">
                        <textarea name="body" rows="3" required></textarea>
                        <input type="hidden" name="parent_id" value="<?= (int)$comment['id']; ?>">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                        <button class="btn small" type="submit" name="add_comment" value="1">Post reply</button>
                      </form>
                    </details>
                  </footer>
                  <?php if (!empty($comment['children'])): ?>
                    <div class="note-comment__children">
                      <?php foreach ($comment['children'] as $child) { $renderComment($child, $renderComment); } ?>
                    </div>
                  <?php endif; ?>
                </article>
                <?php
            };

            foreach ($commentThreads as $comment) {
                $renderComment($comment, $renderComment);
            }
            ?>
          </div>
        <?php endif; ?>

        <form method="post" class="note-comment-form note-comment-form--new">
          <label>
            <span class="lbl">Add a reply</span>
            <textarea name="body" rows="4" required><?= sanitize($_POST['body'] ?? ''); ?></textarea>
          </label>
          <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
          <button class="btn primary" type="submit" name="add_comment" value="1">Post reply</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</section>

<div id="noteViewPhotoModal" class="photo-modal hidden" aria-hidden="true">
  <div class="photo-modal-backdrop" data-close-note-view></div>
  <div class="photo-modal-box" role="dialog" aria-modal="true" aria-labelledby="noteViewPhotoModalTitle">
    <div class="photo-modal-header">
      <h3 id="noteViewPhotoModalTitle">Photos</h3>
      <button class="close-btn" type="button" title="Close" data-close-note-view>&times;</button>
    </div>
    <div id="noteViewPhotoModalBody" class="photo-modal-body"></div>
  </div>
</div>

<?php if ($canShare): ?>
<div id="noteShareModal" class="share-modal hidden" aria-hidden="true">
  <div class="share-modal__overlay" data-share-close></div>
  <div class="share-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="noteShareTitle">
    <header class="share-modal__header">
      <div>
        <h3 id="noteShareTitle">Share this note</h3>
        <p class="share-modal__hint">Choose teammates who should have access to this page.</p>
      </div>
      <button class="close-btn" type="button" title="Close" data-share-close>&times;</button>
    </header>
    <form method="post" id="noteShareForm" class="share-modal__form">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
      <input type="hidden" name="update_shares" value="1">
      <div class="share-modal__body">
        <label class="share-modal__search">
          <span class="visually-hidden">Search teammates</span>
          <input type="search" id="noteShareSearch" placeholder="Search teammates…" autocomplete="off">
        </label>
        <div class="share-modal__list" id="noteShareOptions">
          <?php if ($shareOptions): ?>
            <?php foreach ($shareOptions as $option):
              $uid = (int)$option['id'];
              $label = $option['label'];
              $checked = in_array($uid, $currentShareIds, true);
              $isOwner = !empty($option['is_owner']);
            ?>
              <label class="share-modal__option" data-share-option data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="checkbox" name="shared_ids[]" value="<?= $uid; ?>" <?= $checked ? 'checked' : ''; ?> <?= $isOwner ? 'disabled' : ''; ?>>
                <span><?= sanitize($label); ?></span>
                <?php if ($isOwner): ?><span class="share-modal__badge">Owner</span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="share-modal__empty">No teammates found. Add users to share this note.</p>
          <?php endif; ?>
        </div>
        <p class="share-modal__empty" id="noteShareEmpty" hidden>No matches.</p>
      </div>
      <footer class="share-modal__footer">
        <div class="share-modal__status" id="noteShareStatus" role="status" aria-live="polite"></div>
        <button class="btn primary" type="submit">Save access</button>
      </footer>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.note-page{ display:grid; gap:1.25rem; padding-bottom:2rem; }
.note-hero{ position:relative; border-radius:16px; overflow:hidden; background:#fff; border:1px solid #e2e8f0; box-shadow:0 8px 24px rgba(15,23,42,.05); }
.note-hero.has-cover{ color:#0f172a; }
.note-hero__cover{ position:absolute; inset:0; background-size:cover; background-position:center; opacity:.18; }
.note-hero__inner{ position:relative; padding:1.75rem 2rem; display:flex; flex-direction:column; gap:1rem; }
.note-hero__top{ display:flex; align-items:flex-start; justify-content:space-between; gap:1.25rem; flex-wrap:wrap; }
.note-hero__identity{ display:flex; gap:1rem; align-items:center; }
.note-icon{ width:56px; height:56px; border-radius:14px; display:grid; place-items:center; font-size:1.75rem; background:#f1f5f9; border:1px solid #e2e8f0; }
.note-hero h1{ margin:0; font-size:1.8rem; line-height:1.2; font-weight:600; }
.note-meta{ display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; font-size:.85rem; color:#475569; }
.note-meta__date{ color:#475569; }
.note-meta__chip{ background:#e2e8f0; padding:.2rem .6rem; border-radius:999px; font-size:.8rem; }
.note-meta__shares{ display:flex; gap:.35rem; flex-wrap:wrap; }
.note-hero__actions{ display:flex; gap:.5rem; flex-wrap:wrap; }
.note-tags{ margin-top:.5rem; display:flex; gap:.35rem; flex-wrap:wrap; }
.note-tag{ display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .6rem; border-radius:999px; background:#f8fafc; color:#1f2937; font-size:.8rem; border:1px solid #e2e8f0; }
.note-tag::before{ content:''; width:6px; height:6px; border-radius:50%; background:var(--tag-color,#6366f1); }

.note-layout{ display:grid; gap:1.25rem; }
@media (min-width: 1100px){ .note-layout{ grid-template-columns: minmax(0,1fr) 300px; align-items:start; } }

.note-content{ padding:1.75rem; display:grid; gap:1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 6px 18px rgba(15,23,42,.04); }
.note-blocks{ display:grid; gap:1.25rem; }
.note-block p{ margin:0; font-size:1rem; line-height:1.6; color:#0f172a; }
.note-block__heading{ margin:0; font-weight:600; color:#0f172a; }
.note-block__heading--h1{ font-size:1.7rem; }
.note-block__heading--h2{ font-size:1.35rem; }
.note-block__heading--h3{ font-size:1.2rem; }
.note-block__todo{ display:flex; gap:.6rem; align-items:flex-start; font-size:1rem; line-height:1.55; }
.note-block__todo input{ margin-top:.2rem; width:1rem; height:1rem; }
.note-block__list{ margin:0; padding-left:1.25rem; display:grid; gap:.4rem; font-size:1rem; line-height:1.55; color:#0f172a; }
.note-block__quote{ margin:0; padding:1rem 1.2rem; border-left:3px solid #6366f1; background:#f5f3ff; border-radius:0 .75rem .75rem 0; color:#312e81; font-size:1rem; }
.note-block__callout{ display:flex; gap:.75rem; padding:1rem; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0; border-left:4px solid var(--callout-accent,#6366f1); }
.note-block__callout-icon{ font-size:1.35rem; }
.note-block__divider{ height:1px; background:#e2e8f0; }

.note-sidebar{ display:grid; gap:1rem; }
.note-panel{ padding:1.25rem 1.5rem; display:grid; gap:1rem; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 4px 14px rgba(15,23,42,.04); }
.note-panel__header{ display:flex; justify-content:space-between; align-items:center; gap:.75rem; }
.note-panel__header h2{ margin:0; font-size:1.05rem; font-weight:600; }
.note-panel__body{ display:grid; gap:.65rem; }
.note-properties{ margin:0; padding:0; display:grid; gap:.75rem; }
.note-properties__item{ display:grid; gap:.35rem; }
.note-properties__item dt{ font-size:.78rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.note-properties__item dd{ margin:0; font-size:.95rem; color:#0f172a; }
.note-detail-row{ display:flex; justify-content:space-between; gap:.75rem; font-size:.9rem; }
.note-detail-label{ font-weight:600; color:#475569; }
.note-detail-value{ display:flex; gap:.3rem; flex-wrap:wrap; align-items:center; }
.note-photos{ display:grid; gap:.4rem; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); }
.note-photo-thumb{ display:block; border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; }
.note-photo-thumb img{ width:100%; height:100%; object-fit:cover; aspect-ratio:4/3; }
.note-photo-empty{ border:1px dashed #cbd5f5; border-radius:10px; display:grid; place-items:center; min-height:100px; font-size:.8rem; color:#64748b; }

.note-discussion{ padding:1.75rem; display:grid; gap:1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 6px 18px rgba(15,23,42,.04); }
.note-discussion__body{ display:grid; gap:1rem; }
.note-comments{ display:grid; gap:1rem; }
.note-comment{ border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.1rem; background:#fff; display:grid; gap:.6rem; }
.note-comment__header{ display:flex; justify-content:space-between; gap:.75rem; align-items:flex-start; }
.note-comment__timestamp{ display:block; font-size:.78rem; color:#64748b; margin-top:.15rem; }
.note-comment__delete .btn{ font-size:.75rem; }
.note-comment__body{ white-space:pre-wrap; color:#0f172a; line-height:1.5; }
.note-comment__footer details{ font-size:.88rem; }
.note-comment__children{ border-left:2px solid #e2e8f0; margin-left:.6rem; padding-left:.75rem; display:grid; gap:.75rem; }
.note-comment-form{ display:grid; gap:.65rem; }
.note-comment-form textarea{ width:100%; border-radius:.65rem; border:1px solid #d0d7e2; padding:.6rem .75rem; resize:vertical; font-size:.95rem; }
.note-comment-form--new label{ display:grid; gap:.35rem; }

.share-modal{ position:fixed; inset:0; z-index:40; display:grid; place-items:center; padding:1.5rem; background:rgba(15,23,42,.35); backdrop-filter:blur(4px); transition:opacity .2s ease; }
.share-modal.hidden{ opacity:0; pointer-events:none; }
.share-modal__overlay{ position:absolute; inset:0; }
.share-modal__dialog{ position:relative; z-index:1; width:min(420px, 100%); background:#fff; border-radius:16px; border:1px solid #e2e8f0; box-shadow:0 18px 40px rgba(15,23,42,.2); display:grid; }
.share-modal__header{ padding:1.25rem 1.5rem; display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; border-bottom:1px solid #e2e8f0; }
.share-modal__header h3{ margin:0; font-size:1.1rem; font-weight:600; color:#0f172a; }
.share-modal__hint{ margin:.35rem 0 0; font-size:.85rem; color:#64748b; }
.share-modal__form{ display:grid; gap:0; }
.share-modal__body{ padding:1.25rem 1.5rem; display:grid; gap:1rem; max-height:360px; overflow:auto; }
.share-modal__search input{ width:100%; border:1px solid #d0d7e2; border-radius:10px; padding:.55rem .75rem; font-size:.95rem; }
.share-modal__list{ display:grid; gap:.5rem; }
.share-modal__option{ display:flex; align-items:center; gap:.6rem; padding:.6rem .75rem; border:1px solid #e2e8f0; border-radius:10px; transition:background .15s ease, border-color .15s ease; }
.share-modal__option:hover{ background:#f8fafc; border-color:#cbd5f5; }
.share-modal__option input{ width:1rem; height:1rem; }
.share-modal__badge{ margin-left:auto; font-size:.75rem; color:#64748b; background:#f1f5f9; border-radius:999px; padding:.2rem .55rem; }
.share-modal__empty{ margin:0; font-size:.85rem; color:#64748b; }
.share-modal__footer{ padding:1rem 1.5rem; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:1rem; }
.share-modal__status{ font-size:.85rem; color:#64748b; min-height:1.2rem; }
.share-modal__status.is-error{ color:#b91c1c; }

.badge{ display:inline-flex; align-items:center; gap:.25rem; padding:.28rem .6rem; border-radius:999px; font-size:.72rem; font-weight:600; }
.badge--muted{ background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.badge--blue{ background:#eff6ff; color:#1d4ed8; }
.badge--indigo{ background:#eef2ff; color:#4338ca; }
.badge--purple{ background:#f5f3ff; color:#6b21a8; }
.badge--orange{ background:#fff7ed; color:#c2410c; }
.badge--green{ background:#ecfdf5; color:#15803d; }
.badge--slate{ background:#f1f5f9; color:#334155; }
.badge--danger{ background:#fee2e2; color:#b91c1c; }
.badge--amber{ background:#fef3c7; color:#b45309; }
.badge--teal{ background:#ccfbf1; color:#0f766e; }
.muted{ color:#64748b; }
.is-overdue{ color:#b91c1c; font-weight:600; }

.btn.secondary{ background:#f8fafc; border:1px solid #cbd5f5; color:#1e293b; }
.btn.secondary:hover{ background:#e2e8f0; }

.photo-modal .photo-modal-box{ max-width:960px; width:90vw; height:80vh; border-radius:16px; }
.photo-modal .photo-modal-body{ height:calc(80vh - 56px); }

@media (max-width:720px){
  .note-hero__inner{ padding:1.5rem; }
  .note-icon{ width:48px; height:48px; font-size:1.5rem; }
  .note-layout{ grid-template-columns:1fr; }
  .note-panel{ padding:1.1rem 1.25rem; }
  .note-content{ padding:1.35rem; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const page        = document.querySelector('[data-note-page]');
  const grid        = document.getElementById('noteViewPhotoGrid');
  const modal       = document.getElementById('noteViewPhotoModal');
  const bodyEl      = document.getElementById('noteViewPhotoModalBody');
  const openAllBtn  = document.getElementById('openAllNotePhotos');
  const shareModal  = document.getElementById('noteShareModal');
  const shareForm   = document.getElementById('noteShareForm');
  const shareSearch = document.getElementById('noteShareSearch');
  const shareStatus = document.getElementById('noteShareStatus');
  const shareEmpty  = document.getElementById('noteShareEmpty');
  const shareTrigger= document.querySelector('[data-share-open]');
  const shareSummary= document.querySelector('[data-share-summary]');
  const shareBadges = document.querySelector('[data-share-list]');
  const csrfField   = '<?= CSRF_TOKEN_NAME; ?>';
  const noteId      = page ? (page.getAttribute('data-note-id') || '') : '';
  const csrfToken   = page ? (page.getAttribute('data-csrf') || '') : '';
  const canShare    = page && page.getAttribute('data-can-share') === '1';
  const shareOptionNodes = shareModal ? Array.from(shareModal.querySelectorAll('[data-share-option]')) : [];
  const shareEmptyText = shareSummary ? (shareSummary.getAttribute('data-empty-text') || 'Private') : 'Private';

  const shareState = {
    selected: new Set(),
    options: [],
  };

  if (canShare) {
    const rawConfig = page ? (page.getAttribute('data-share-config') || '') : '';
    if (rawConfig) {
      try {
        const parsed = JSON.parse(rawConfig);
        if (Array.isArray(parsed.selected)) {
          shareState.selected = new Set(parsed.selected.map((v) => Number(v)));
        }
      } catch (err) {
        console.error('Failed to parse share config', err);
      }
    }
    shareState.options = shareOptionNodes.map((node) => {
      const checkbox = node.querySelector('input');
      return {
        id: Number(checkbox ? checkbox.value : 0),
        element: node,
        checkbox,
        label: (node.dataset.label || '').toLowerCase(),
      };
    });
  }

  function lockScroll() {
    document.body.style.overflow = 'hidden';
  }

  function unlockScroll() {
    const photoOpen = modal && !modal.classList.contains('hidden');
    const shareOpen = shareModal && !shareModal.classList.contains('hidden');
    if (!photoOpen && !shareOpen) {
      document.body.style.overflow = '';
    }
  }

  if (page) {
    page.querySelectorAll('[data-block-toggle]').forEach((input) => {
      if (input.disabled) { return; }
      input.addEventListener('change', () => {
        const uid = input.getAttribute('data-block-toggle');
        if (!uid) { return; }
        if (!csrfToken) { input.checked = !input.checked; return; }
        const formData = new FormData();
        formData.append('toggle_block', uid);
        formData.append('checked', input.checked ? '1' : '0');
        formData.append(csrfField, csrfToken);
        fetch(`view.php?id=${noteId}`, {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
          .then((resp) => resp.json())
          .then((data) => {
            if (!data || data.ok) { return; }
            throw new Error(data.message || 'Request failed');
          })
          .catch((err) => {
            console.error(err);
            input.checked = !input.checked;
            alert('We could not update that checklist item.');
          });
      });
    });
  }

  function openModal() {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    lockScroll();
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    if (bodyEl) {
      bodyEl.innerHTML = '';
    }
    unlockScroll();
  }

  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target.matches('[data-close-note-view], .photo-modal-backdrop')) {
        closeModal();
      }
    });
  }

  function injectImages(urls) {
    if (!bodyEl) return;
    const frag = document.createDocumentFragment();
    urls.forEach((u) => {
      if (!u) return;
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.decoding = 'async';
      img.src = u;
      img.alt = 'Note photo';
      frag.appendChild(img);
    });
    bodyEl.innerHTML = '';
    bodyEl.appendChild(frag);
  }

  if (grid) {
    grid.addEventListener('click', (e) => {
      const anchor = e.target.closest('.js-zoom');
      if (!anchor) return;
      if (e.metaKey || e.ctrlKey) return;
      e.preventDefault();
      const imgs = Array.from(grid.querySelectorAll('.js-zoom img')).map((img) => img.getAttribute('src')).filter(Boolean);
      const clicked = anchor.querySelector('img')?.getAttribute('src') || null;
      const ordered = clicked ? [clicked].concat(imgs.filter((u) => u !== clicked)) : imgs;
      if (!ordered.length) return;
      injectImages(ordered);
      openModal();
    });
  }

  if (openAllBtn) {
    openAllBtn.addEventListener('click', () => {
      if (!grid) return;
      const imgs = Array.from(grid.querySelectorAll('.js-zoom img')).map((img) => img.getAttribute('src')).filter(Boolean);
      if (!imgs.length) return;
      injectImages(imgs);
      openModal();
    });
  }

  function applyShareSelections() {
    shareState.options.forEach((opt) => {
      if (!opt.checkbox) return;
      opt.checkbox.checked = shareState.selected.has(opt.id);
    });
  }

  function filterShareOptions(term) {
    if (!canShare) return;
    const normalized = (term || '').trim().toLowerCase();
    let visible = 0;
    shareState.options.forEach((opt) => {
      const matches = normalized === '' || opt.label.includes(normalized);
      if (opt.element) {
        opt.element.hidden = !matches;
      }
      if (matches) {
        visible++;
      }
    });
    if (shareEmpty) {
      shareEmpty.hidden = visible !== 0;
    }
  }

  function updateShareDisplays(shares) {
    if (shareBadges) {
      shareBadges.innerHTML = '';
      if (shares && shares.length) {
        const frag = document.createDocumentFragment();
        shares.forEach((share) => {
          const badge = document.createElement('span');
          badge.className = 'badge badge--muted';
          badge.textContent = share.label || `User #${share.id || ''}`;
          frag.appendChild(badge);
        });
        shareBadges.appendChild(frag);
      }
    }
    if (shareSummary) {
      shareSummary.innerHTML = '';
      if (shares && shares.length) {
        const frag = document.createDocumentFragment();
        shares.forEach((share) => {
          const badge = document.createElement('span');
          badge.className = 'badge badge--muted';
          badge.textContent = share.label || `User #${share.id || ''}`;
          frag.appendChild(badge);
        });
        shareSummary.appendChild(frag);
      } else {
        const span = document.createElement('span');
        span.className = 'muted';
        span.textContent = shareEmptyText;
        shareSummary.appendChild(span);
      }
    }
  }

  function openShareModal() {
    if (!shareModal) return;
    applyShareSelections();
    filterShareOptions(shareSearch ? shareSearch.value : '');
    shareModal.classList.remove('hidden');
    shareModal.setAttribute('aria-hidden', 'false');
    lockScroll();
    if (shareStatus) {
      shareStatus.textContent = '';
      shareStatus.classList.remove('is-error');
    }
    if (shareSearch) {
      shareSearch.focus();
      shareSearch.select();
    }
  }

  function closeShareModal() {
    if (!shareModal) return;
    shareModal.classList.add('hidden');
    shareModal.setAttribute('aria-hidden', 'true');
    if (shareStatus) {
      shareStatus.textContent = '';
      shareStatus.classList.remove('is-error');
    }
    unlockScroll();
  }

  if (shareModal) {
    shareModal.addEventListener('click', (event) => {
      if (event.target.matches('[data-share-close], .share-modal__overlay')) {
        event.preventDefault();
        closeShareModal();
      }
    });
  }

  if (shareTrigger) {
    shareTrigger.addEventListener('click', (event) => {
      event.preventDefault();
      openShareModal();
    });
  }

  if (shareSearch) {
    shareSearch.addEventListener('input', (event) => {
      filterShareOptions(event.target.value || '');
    });
  }

  if (shareForm) {
    shareForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!canShare || !noteId) { return; }
      const formData = new FormData(shareForm);
      fetch(`view.php?id=${noteId}`, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then((resp) => resp.json())
        .then((data) => {
          if (!data || !data.ok) {
            throw new Error((data && data.message) || 'Failed to update shares');
          }
          const nextSelected = Array.isArray(data.selected) ? data.selected.map((v) => Number(v)) : [];
          shareState.selected = new Set(nextSelected);
          applyShareSelections();
          updateShareDisplays(Array.isArray(data.shares) ? data.shares : []);
          if (shareStatus) {
            shareStatus.textContent = 'Access updated';
            shareStatus.classList.remove('is-error');
            setTimeout(() => {
              if (shareStatus.textContent === 'Access updated') {
                shareStatus.textContent = '';
              }
            }, 3000);
          }
        })
        .catch((err) => {
          console.error(err);
          if (shareStatus) {
            shareStatus.textContent = 'Could not update shares.';
            shareStatus.classList.add('is-error');
          }
        });
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') { return; }
    if (shareModal && !shareModal.classList.contains('hidden')) {
      closeShareModal();
      event.preventDefault();
      return;
    }
    if (modal && !modal.classList.contains('hidden')) {
      closeModal();
      event.preventDefault();
    }
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
