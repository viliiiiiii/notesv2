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
<section class="obsidian-shell obsidian-shell--viewer" data-theme="obsidian" data-note-page data-note-id="<?= (int)$id; ?>" data-csrf="<?= sanitize($csrfToken); ?>" data-can-share="<?= $canShare ? '1' : '0'; ?>" data-share-config="<?= $shareConfigAttr; ?>">
  <header class="obsidian-header obsidian-header--viewer">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Note detail</span>
      <div class="obsidian-detail">
        <span class="obsidian-detail__icon"><?= sanitize($meta['icon'] ?: '🗒️'); ?></span>
        <div class="obsidian-detail__content">
          <h1><?= sanitize($note['title'] ?: 'Untitled'); ?></h1>
          <div class="obsidian-detail__meta">
            <span class="obsidian-status obsidian-status--<?= sanitize($statusSlug); ?>"><?= sanitize($statusLabel); ?></span>
            <?php if ($noteDateFormatted): ?>
              <span class="obsidian-detail__timestamp"><?= sanitize($noteDateFormatted); ?></span>
            <?php endif; ?>
            <?php if ($commentCount): ?>
              <span class="obsidian-detail__count">💬 <?= (int)$commentCount; ?></span>
            <?php endif; ?>
            <?php if (array_filter($photos)): ?>
              <span class="obsidian-detail__count">📸 <?= count(array_filter($photos)); ?></span>
            <?php endif; ?>
          </div>
          <div class="obsidian-detail__shares" data-share-list>
            <?php if ($shareDetails): ?>
              <?php foreach ($shareDetails as $share): ?>
                <span class="obsidian-pill"><?= sanitize($share['label']); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="obsidian-pill is-muted" data-share-empty>Private</span>
            <?php endif; ?>
          </div>
          <?php if ($tags): ?>
          <div class="obsidian-detail__tags">
            <?php foreach ($tags as $tag): ?>
              <span class="obsidian-tag" style="--tag-color: <?= sanitize($tag['color'] ?? notes_random_tag_color()); ?>"><?= sanitize($tag['label']); ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="obsidian-header__actions">
      <a class="btn obsidian-btn--ghost" href="index.php">Back to vault</a>
      <?php if ($canShare): ?>
        <button class="btn obsidian-btn" type="button" data-share-open>Share</button>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn obsidian-primary" href="edit.php?id=<?= (int)$note['id']; ?>">Edit note</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <div class="obsidian-layout obsidian-layout--viewer">
    <div class="obsidian-main obsidian-main--viewer">
      <article class="obsidian-reader">
        <?php if ($blocks): ?>
          <div class="obsidian-blocks">
            <?php foreach ($blocks as $block):
              $type = $block['type'] ?? 'paragraph';
              $text = (string)($block['text'] ?? '');
              $items = is_array($block['items'] ?? null) ? $block['items'] : [];
              $checked = !empty($block['checked']);
              $uid = $block['uid'] ?? '';
              $calloutColor = $block['color'] ?? null;
              $calloutIcon  = $block['icon'] ?? null;
            ?>
            <div class="obsidian-block obsidian-block--<?= sanitize($type); ?>">
              <?php switch ($type) {
                case 'heading1': ?>
                  <h2 class="obsidian-block__heading obsidian-block__heading--h1"><?= nl2br(sanitize($text)); ?></h2>
                <?php break;
                case 'heading2': ?>
                  <h3 class="obsidian-block__heading obsidian-block__heading--h2"><?= nl2br(sanitize($text)); ?></h3>
                <?php break;
                case 'heading3': ?>
                  <h4 class="obsidian-block__heading obsidian-block__heading--h3"><?= nl2br(sanitize($text)); ?></h4>
                <?php break;
                case 'todo': ?>
                  <label class="obsidian-block__todo">
                    <input type="checkbox" value="1" data-block-toggle="<?= sanitize($uid); ?>" <?= $checked ? 'checked' : ''; ?><?= $canEdit ? '' : ' disabled'; ?>>
                    <span><?= nl2br(sanitize($text)); ?></span>
                  </label>
                <?php break;
                case 'bulleted': ?>
                  <ul class="obsidian-block__list obsidian-block__list--bulleted">
                    <?php foreach ($items as $item): ?>
                      <li><?= nl2br(sanitize((string)$item)); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php break;
                case 'numbered': ?>
                  <ol class="obsidian-block__list obsidian-block__list--numbered">
                    <?php foreach ($items as $item): ?>
                      <li><?= nl2br(sanitize((string)$item)); ?></li>
                    <?php endforeach; ?>
                  </ol>
                <?php break;
                case 'quote': ?>
                  <blockquote class="obsidian-block__quote"><?= nl2br(sanitize($text)); ?></blockquote>
                <?php break;
                case 'callout': ?>
                  <div class="obsidian-block__callout"<?= $calloutColor ? ' style="--callout-accent:' . sanitize($calloutColor) . ';"' : ''; ?>>
                    <div class="obsidian-block__callout-icon" aria-hidden="true"><?= $calloutIcon ? sanitize($calloutIcon) : '💡'; ?></div>
                    <div><?= nl2br(sanitize($text)); ?></div>
                  </div>
                <?php break;
                case 'divider': ?>
                  <div class="obsidian-block__divider" role="presentation"></div>
                <?php break;
                default: ?>
                  <p class="obsidian-block__text"><?= nl2br(sanitize($text)); ?></p>
                <?php break;
              } ?>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="obsidian-empty">No content yet.</p>
        <?php endif; ?>
      </article>

      <?php if ($commentsEnabled): ?>
      <section class="obsidian-panel obsidian-panel--comments" id="comments">
        <header class="obsidian-panel__header">
          <div>
            <h2>Discussion</h2>
            <p class="obsidian-panel__hint">Collaborate with teammates and keep context alongside the note.</p>
          </div>
          <span class="obsidian-pill is-muted"><?= (int)$commentCount; ?> replies</span>
        </header>
        <div class="obsidian-comments">
          <?php if (!$commentThreads): ?>
            <p class="obsidian-empty">No replies yet.</p>
          <?php else: ?>
            <?php
            $renderComment = static function (array $comment, callable $renderComment) use ($csrfToken, $note) {
                ?>
                <article class="obsidian-comment" id="comment-<?= (int)$comment['id']; ?>">
                  <header class="obsidian-comment__header">
                    <div>
                      <strong><?= sanitize($comment['author_label']); ?></strong>
                      <span class="obsidian-comment__timestamp"><?= sanitize(substr((string)($comment['created_at'] ?? ''), 0, 16)); ?></span>
                    </div>
                    <?php if (!empty($comment['can_delete'])): ?>
                      <form method="post" class="obsidian-comment__delete" onsubmit="return confirm('Delete this reply?');">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                        <button class="btn obsidian-btn--ghost small" type="submit" name="delete_comment" value="<?= (int)$comment['id']; ?>">Delete</button>
                      </form>
                    <?php endif; ?>
                  </header>
                  <div class="obsidian-comment__body"><?= nl2br(sanitize($comment['body'] ?? '')); ?></div>
                  <footer class="obsidian-comment__footer">
                    <button class="btn obsidian-btn--ghost small" type="button" data-reply-toggle>Reply</button>
                    <form method="post" class="obsidian-comment-form obsidian-comment-form--inline" data-reply-form hidden>
                      <textarea name="body" rows="3" required></textarea>
                      <input type="hidden" name="parent_id" value="<?= (int)$comment['id']; ?>">
                      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                      <div class="obsidian-comment-actions">
                        <button class="btn obsidian-primary small" type="submit" name="add_comment" value="1">Post reply</button>
                        <button class="btn obsidian-btn--ghost small" type="button" data-reply-cancel>Cancel</button>
                      </div>
                    </form>
                  </footer>
                  <?php if (!empty($comment['children'])): ?>
                    <div class="obsidian-comment__children">
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

        <form method="post" class="obsidian-comment-form obsidian-comment-form--new">
          <label>
            <span class="obsidian-field-label">Add a reply</span>
            <textarea name="body" rows="4" required><?= sanitize($_POST['body'] ?? ''); ?></textarea>
          </label>
          <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
          <div class="obsidian-comment-actions">
            <button class="btn obsidian-primary" type="submit" name="add_comment" value="1">Post comment</button>
          </div>
        </form>
      </section>
      <?php else: ?>
        <section class="obsidian-panel obsidian-panel--comments" id="comments">
          <header class="obsidian-panel__header">
            <h2>Discussion</h2>
          </header>
          <p class="obsidian-empty">Commenting is disabled for this installation.</p>
        </section>
      <?php endif; ?>
    </div>

    <aside class="obsidian-sidebar obsidian-sidebar--viewer">
      <section class="obsidian-panel obsidian-panel--properties">
        <header class="obsidian-panel__header">
          <h2>Properties</h2>
        </header>
        <dl class="obsidian-properties">
          <?php foreach ($propertyLabels as $key => $label):
            $value = $properties[$key] ?? '';
            if ($key === 'due_date' && $value) {
                try {
                    $dt = new DateTimeImmutable($value);
                    $formatted = $dt->format('M j, Y');
                    if ($dt < new DateTimeImmutable('today')) {
                        $value = '<span class="obsidian-overdue">' . sanitize($formatted) . '</span>';
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
                $value = $value !== '' ? sanitize((string)$value) : '<span class="obsidian-muted">—</span>';
            }
          ?>
          <div class="obsidian-properties__item">
            <dt><?= sanitize($label); ?></dt>
            <dd><?= $value; ?></dd>
          </div>
          <?php endforeach; ?>
        </dl>
      </section>

      <section class="obsidian-panel obsidian-panel--details">
        <header class="obsidian-panel__header">
          <h2>Details</h2>
        </header>
        <div class="obsidian-detail-grid">
          <div>
            <span class="obsidian-field-label">Owner</span>
            <span><?= sanitize($ownerLabel); ?></span>
          </div>
          <div>
            <span class="obsidian-field-label">Shared with</span>
            <div class="obsidian-detail__shares" data-share-summary data-empty-text="Private">
              <?php if ($shareDetails): ?>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="obsidian-pill"><?= sanitize($share['label']); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="obsidian-muted" data-share-empty>Private</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="obsidian-panel obsidian-panel--attachments">
        <header class="obsidian-panel__header">
          <h2>Attachments</h2>
          <?php if (array_filter($photos)): ?>
            <button class="btn obsidian-btn--ghost small" type="button" id="openAllNotePhotos">Open gallery</button>
          <?php endif; ?>
        </header>
        <div class="obsidian-attachments" id="noteViewPhotoGrid">
          <?php for ($i = 1; $i <= 3; $i++): $p = $photos[$i] ?? null; ?>
            <?php if ($p): ?>
              <a href="<?= sanitize($p['url']); ?>" class="obsidian-attachment" target="_blank" rel="noopener">
                <img src="<?= sanitize($p['url']); ?>" alt="Note photo <?= $i; ?>" loading="lazy" decoding="async">
              </a>
            <?php else: ?>
              <div class="obsidian-attachment obsidian-attachment--empty">Slot <?= $i; ?></div>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      </section>
    </aside>
  </div>
</section>

<div id="noteViewPhotoModal" class="obsidian-photo-modal hidden" aria-hidden="true">
  <div class="obsidian-photo-modal__overlay" data-close-note-view></div>
  <div class="obsidian-photo-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="noteViewPhotoModalTitle">
    <header class="obsidian-photo-modal__header">
      <h3 id="noteViewPhotoModalTitle">Photos</h3>
      <button class="obsidian-photo-modal__close" type="button" title="Close" data-close-note-view>&times;</button>
    </header>
    <div id="noteViewPhotoModalBody" class="obsidian-photo-modal__body"></div>
  </div>
</div>

<?php if ($canShare): ?>
<div class="obsidian-modal hidden" id="noteShareModal" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog obsidian-modal__dialog--share" role="dialog" aria-modal="true" aria-labelledby="noteShareTitle">
    <header class="obsidian-modal__header">
      <div>
        <h3 id="noteShareTitle">Share this note</h3>
        <p class="obsidian-modal__subtitle">Choose teammates who should have access.</p>
      </div>
      <button type="button" class="obsidian-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" id="noteShareForm" class="obsidian-modal__form">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
      <input type="hidden" name="update_shares" value="1">
      <div class="obsidian-modal__body">
        <label class="obsidian-modal__search">
          <span class="visually-hidden">Search teammates</span>
          <input type="search" id="noteShareSearch" placeholder="Search teammates…" autocomplete="off">
        </label>
        <div class="obsidian-modal__options" id="noteShareOptions">
          <?php if ($shareOptions): ?>
            <?php foreach ($shareOptions as $option):
              $uid = (int)$option['id'];
              $label = $option['label'];
              $checked = in_array($uid, $currentShareIds, true);
              $isOwner = !empty($option['is_owner']);
            ?>
            <label class="obsidian-modal__option" data-share-option data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="checkbox" name="shared_ids[]" value="<?= $uid; ?>" <?= $checked ? 'checked' : ''; ?> <?= $isOwner ? 'disabled' : ''; ?>>
              <span><?= sanitize($label); ?></span>
              <?php if ($isOwner): ?><span class="obsidian-modal__badge">Owner</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="obsidian-modal__empty">No teammates found. Add users to share this note.</p>
          <?php endif; ?>
        </div>
        <p class="obsidian-modal__empty" id="noteShareEmpty" hidden>No matches.</p>
      </div>
      <footer class="obsidian-modal__footer">
        <span class="obsidian-modal__status" id="noteShareStatus" role="status" aria-live="polite"></span>
        <button class="btn obsidian-primary" type="submit">Save access</button>
      </footer>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.obsidian-shell{position:relative;background:radial-gradient(circle at top left,#1e293b,#0f172a);color:#e2e8f0;border-radius:24px;padding:2rem 2.25rem;margin-bottom:2rem;box-shadow:0 30px 60px rgba(15,23,42,.35);}
.obsidian-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1.25rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.9rem;font-weight:700;color:#f8fafc;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:.78rem;color:#94a3b8;display:block;margin-bottom:.25rem;}
.obsidian-header__actions{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;}
.btn.obsidian-primary{background:#6366f1;border:none;color:#f8fafc;border-radius:999px;padding:.6rem 1.4rem;font-weight:600;box-shadow:0 14px 30px rgba(99,102,241,.35);cursor:pointer;}
.btn.obsidian-btn{background:rgba(99,102,241,.25);border:1px solid rgba(99,102,241,.45);color:#e0e7ff;border-radius:999px;padding:.45rem 1rem;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost{background:transparent;border:1px solid rgba(148,163,184,.35);color:#cbd5f5;border-radius:999px;padding:.45rem 1rem;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost.small{padding:.35rem .9rem;font-size:.82rem;}
.btn.obsidian-btn:hover{background:rgba(99,102,241,.35);}
.btn.obsidian-btn--ghost:hover{border-color:rgba(148,163,184,.6);color:#f8fafc;}
.obsidian-layout{display:grid;gap:1.5rem;grid-template-columns:minmax(0,1fr) 320px;align-items:start;}
.obsidian-sidebar{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.14);border-radius:18px;padding:1.4rem;display:grid;gap:1.4rem;}
.obsidian-main{background:rgba(15,23,42,.35);border:1px solid rgba(148,163,184,.1);border-radius:18px;padding:1.35rem;display:grid;gap:1.5rem;}
.obsidian-panel{background:rgba(15,23,42,.5);border:1px solid rgba(148,163,184,.16);border-radius:16px;padding:1.1rem 1.2rem;display:grid;gap:1rem;}
.obsidian-panel__header{display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem;flex-wrap:wrap;}
.obsidian-panel__header h2{margin:0;font-size:1.05rem;color:#f8fafc;}
.obsidian-panel__hint{margin:.3rem 0 0;font-size:.85rem;color:#94a3b8;}
.obsidian-detail{display:flex;gap:1rem;align-items:flex-start;}
.obsidian-detail__icon{width:56px;height:56px;border-radius:16px;background:rgba(99,102,241,.25);display:grid;place-items:center;font-size:1.5rem;}
.obsidian-detail__content{display:grid;gap:.6rem;}
.obsidian-detail__meta{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;font-size:.85rem;color:#cbd5f5;}
.obsidian-detail__shares{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-detail__tags{display:flex;gap:.4rem;flex-wrap:wrap;}
.obsidian-detail__count{background:rgba(148,163,184,.18);border-radius:999px;padding:.25rem .6rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-status{display:inline-flex;align-items:center;background:rgba(99,102,241,.25);border-radius:999px;padding:.25rem .65rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-status--idea{background:rgba(59,130,246,.25);color:#bfdbfe;}
.obsidian-status--in_progress{background:rgba(129,140,248,.25);color:#c7d2fe;}
.obsidian-status--review{background:rgba(196,181,253,.2);color:#ede9fe;}
.obsidian-status--blocked{background:rgba(251,191,36,.2);color:#fde68a;}
.obsidian-status--complete{background:rgba(16,185,129,.2);color:#a7f3d0;}
.obsidian-status--archived{background:rgba(148,163,184,.2);color:#e2e8f0;}
.obsidian-pill{display:inline-flex;align-items:center;background:rgba(99,102,241,.25);border-radius:999px;padding:.25rem .65rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-pill.is-muted{background:rgba(148,163,184,.18);color:#cbd5f5;}
.obsidian-tag{display:inline-flex;align-items:center;gap:.3rem;background:rgba(99,102,241,.2);border-radius:999px;padding:.25rem .6rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-tag::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--tag-color,#6366f1);}
.obsidian-reader{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:1.35rem;display:grid;gap:1.2rem;}
.obsidian-blocks{display:grid;gap:1.1rem;}
.obsidian-block__heading{margin:0;color:#f8fafc;}
.obsidian-block__heading--h1{font-size:1.7rem;}
.obsidian-block__heading--h2{font-size:1.4rem;}
.obsidian-block__heading--h3{font-size:1.2rem;}
.obsidian-block__text{margin:0;color:#f8fafc;font-size:1.02rem;line-height:1.6;}
.obsidian-block__todo{display:flex;gap:.6rem;align-items:flex-start;color:#e2e8f0;font-size:1rem;line-height:1.55;}
.obsidian-block__todo input{margin-top:.25rem;width:1rem;height:1rem;}
.obsidian-block__list{margin:0;padding-left:1.35rem;display:grid;gap:.45rem;color:#e2e8f0;line-height:1.6;}
.obsidian-block__quote{margin:0;padding:1rem 1.2rem;border-left:3px solid #6366f1;background:rgba(79,70,229,.18);border-radius:0 .9rem .9rem 0;color:#ede9fe;}
.obsidian-block__callout{display:flex;gap:.8rem;padding:1rem;background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.28);border-left:4px solid var(--callout-accent,#6366f1);border-radius:12px;color:#e2e8f0;}
.obsidian-block__callout-icon{font-size:1.35rem;}
.obsidian-block__divider{height:2px;background:linear-gradient(90deg,rgba(99,102,241,.35),rgba(148,163,184,.25));border-radius:999px;}
.obsidian-empty{margin:0;color:#94a3b8;}
.obsidian-properties{margin:0;padding:0;display:grid;gap:.8rem;}
.obsidian-properties__item{display:grid;gap:.35rem;}
.obsidian-properties__item dt{font-size:.75rem;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;}
.obsidian-properties__item dd{margin:0;font-size:.95rem;color:#f8fafc;}
.obsidian-field-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;display:block;margin-bottom:.35rem;}
.obsidian-detail-grid{display:grid;gap:.75rem;}
.obsidian-muted{color:#94a3b8;}
.obsidian-overdue{color:#fca5a5;font-weight:600;}
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.28rem .6rem;border-radius:999px;font-size:.72rem;font-weight:600;}
.badge--muted{background:rgba(148,163,184,.2);color:#e2e8f0;}
.badge--blue{background:rgba(59,130,246,.25);color:#bfdbfe;}
.badge--indigo{background:rgba(99,102,241,.25);color:#c7d2fe;}
.badge--purple{background:rgba(168,85,247,.25);color:#ede9fe;}
.badge--orange{background:rgba(251,191,36,.25);color:#fcd34d;}
.badge--green{background:rgba(16,185,129,.25);color:#bbf7d0;}
.badge--slate{background:rgba(71,85,105,.35);color:#e2e8f0;}
.badge--danger{background:rgba(248,113,113,.25);color:#fecaca;}
.badge--amber{background:rgba(251,191,36,.25);color:#fde68a;}
.badge--teal{background:rgba(20,184,166,.25);color:#99f6e4;}
.obsidian-attachments{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));}
.obsidian-attachment{display:block;border-radius:12px;overflow:hidden;border:1px solid rgba(148,163,184,.25);}
.obsidian-attachment img{width:100%;height:100%;object-fit:cover;aspect-ratio:4/3;}
.obsidian-attachment--empty{display:grid;place-items:center;min-height:110px;color:#94a3b8;background:rgba(15,23,42,.45);border:1px dashed rgba(148,163,184,.3);}
.obsidian-comments{display:grid;gap:1rem;}
.obsidian-comment{border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:.95rem 1.1rem;background:rgba(15,23,42,.7);display:grid;gap:.75rem;}
.obsidian-comment__header{display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;flex-wrap:wrap;}
.obsidian-comment__timestamp{display:block;font-size:.78rem;color:#94a3b8;margin-top:.2rem;}
.obsidian-comment__body{white-space:pre-wrap;color:#f1f5f9;line-height:1.6;}
.obsidian-comment__footer{display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;}
.obsidian-comment__children{border-left:2px solid rgba(148,163,184,.25);margin-left:.6rem;padding-left:.75rem;display:grid;gap:.75rem;}
.obsidian-comment-form{display:grid;gap:.75rem;}
.obsidian-comment-form textarea{width:100%;border-radius:.85rem;border:1px solid rgba(148,163,184,.35);padding:.65rem .85rem;background:rgba(15,23,42,.75);color:#f8fafc;resize:vertical;}
.obsidian-comment-form--inline{margin-top:.5rem;}
.obsidian-comment-form--inline[hidden]{display:none;}
.obsidian-comment-actions{display:flex;gap:.5rem;flex-wrap:wrap;}
.obsidian-photo-modal{position:fixed;inset:0;z-index:60;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.65);backdrop-filter:blur(10px);transition:opacity .2s ease;}
.obsidian-photo-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-photo-modal__overlay{position:absolute;inset:0;}
.obsidian-photo-modal__dialog{position:relative;z-index:1;width:min(960px,100%);background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:20px;display:grid;gap:0;box-shadow:0 30px 60px rgba(15,23,42,.55);}
.obsidian-photo-modal__header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.2rem;border-bottom:1px solid rgba(148,163,184,.25);}
.obsidian-photo-modal__header h3{margin:0;color:#f8fafc;}
.obsidian-photo-modal__close{background:none;border:none;color:#cbd5f5;font-size:1.8rem;cursor:pointer;}
.obsidian-photo-modal__body{padding:1rem;max-height:70vh;overflow:auto;display:grid;gap:.75rem;}
.obsidian-photo-modal__body a{display:block;border-radius:12px;overflow:hidden;border:1px solid rgba(148,163,184,.25);}
.obsidian-photo-modal__body img{width:100%;height:100%;object-fit:cover;}
.obsidian-modal{position:fixed;inset:0;z-index:70;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.6);backdrop-filter:blur(8px);transition:opacity .2s ease;}
.obsidian-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-modal__overlay{position:absolute;inset:0;}
.obsidian-modal__dialog{position:relative;z-index:1;width:min(520px,100%);background:#111827;border:1px solid rgba(148,163,184,.25);border-radius:18px;display:grid;gap:.75rem;box-shadow:0 30px 60px rgba(15,23,42,.55);}
.obsidian-modal__dialog--share{padding:0;}
.obsidian-modal__header{display:flex;justify-content:space-between;align-items:flex-start;padding:1rem 1.2rem;border-bottom:1px solid rgba(148,163,184,.25);gap:.75rem;}
.obsidian-modal__header h3{margin:0;color:#f8fafc;}
.obsidian-modal__subtitle{margin:.35rem 0 0;color:#94a3b8;font-size:.85rem;}
.obsidian-modal__close{background:none;border:none;color:#cbd5f5;font-size:1.6rem;cursor:pointer;}
.obsidian-modal__form{display:grid;gap:0;}
.obsidian-modal__body{padding:1rem 1.2rem;display:grid;gap:.9rem;max-height:340px;overflow:auto;}
.obsidian-modal__search input{width:100%;background:rgba(15,23,42,.7);border:1px solid rgba(148,163,184,.3);border-radius:12px;padding:.6rem .8rem;color:#f8fafc;font-size:.95rem;}
.obsidian-modal__options{display:grid;gap:.6rem;}
.obsidian-modal__option{display:flex;gap:.6rem;align-items:center;padding:.6rem .8rem;background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.3);border-radius:12px;cursor:pointer;transition:border-color .2s ease,background .2s ease;}
.obsidian-modal__option:hover{border-color:#6366f1;}
.obsidian-modal__option input{width:1rem;height:1rem;}
.obsidian-modal__badge{margin-left:auto;font-size:.75rem;color:#94a3b8;background:rgba(148,163,184,.2);border-radius:999px;padding:.2rem .55rem;}
.obsidian-modal__empty{margin:0;font-size:.85rem;color:#94a3b8;}
.obsidian-modal__footer{padding:1rem 1.2rem;border-top:1px solid rgba(148,163,184,.25);display:flex;justify-content:space-between;align-items:center;gap:.75rem;}
.obsidian-modal__status{font-size:.85rem;color:#94a3b8;min-height:1.2rem;}
.obsidian-modal__status.is-error{color:#fca5a5;}
.flash{border-radius:12px;padding:.85rem 1.1rem;margin-bottom:1rem;}
.flash-error{background:rgba(239,68,68,.15);border:1px solid rgba(248,113,113,.3);color:#fecaca;}
.visually-hidden{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;}
@media (max-width:1080px){.obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar,.obsidian-main{grid-column:1 / -1;}}
@media (max-width:720px){.obsidian-shell{padding:1.5rem;} .obsidian-header__titles h1{font-size:1.6rem;} .obsidian-detail{flex-direction:column;align-items:flex-start;} .obsidian-detail__icon{width:48px;height:48px;font-size:1.3rem;} }
</style>

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
.note-page{ display:grid; gap:1rem; padding-bottom:1.5rem; }
.note-hero{ position:relative; border-radius:14px; overflow:hidden; background:#fff; border:1px solid #e2e8f0; box-shadow:0 6px 18px rgba(15,23,42,.04); }
.note-hero.has-cover{ color:#0f172a; }
.note-hero__cover{ position:absolute; inset:0; background-size:cover; background-position:center; opacity:.16; }
.note-hero__inner{ position:relative; padding:1.4rem 1.6rem; display:flex; flex-direction:column; gap:.85rem; }
.note-hero__top{ display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.note-hero__identity{ display:flex; gap:.85rem; align-items:center; }
.note-icon{ width:48px; height:48px; border-radius:12px; display:grid; place-items:center; font-size:1.5rem; background:#f1f5f9; border:1px solid #e2e8f0; }
.note-hero h1{ margin:0; font-size:1.6rem; line-height:1.2; font-weight:600; }
.note-meta{ display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; font-size:.85rem; color:#475569; }
.note-meta__date{ color:#475569; }
.note-meta__chip{ background:#e2e8f0; padding:.2rem .6rem; border-radius:999px; font-size:.8rem; }
.note-meta__shares{ display:flex; gap:.35rem; flex-wrap:wrap; }
.note-hero__actions{ display:flex; gap:.5rem; flex-wrap:wrap; }
.note-tags{ margin-top:.5rem; display:flex; gap:.35rem; flex-wrap:wrap; }
.note-tag{ display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .6rem; border-radius:999px; background:#f8fafc; color:#1f2937; font-size:.8rem; border:1px solid #e2e8f0; }
.note-tag::before{ content:''; width:6px; height:6px; border-radius:50%; background:var(--tag-color,#6366f1); }

.note-layout{ display:grid; gap:1rem; }
@media (min-width: 1100px){ .note-layout{ grid-template-columns: minmax(0,1fr) 300px; align-items:start; } }

.note-content{ padding:1.4rem; display:grid; gap:1rem; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 5px 16px rgba(15,23,42,.035); }
.note-blocks{ display:grid; gap:1rem; }
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

.note-sidebar{ display:grid; gap:.85rem; }
.note-panel{ padding:1.1rem 1.3rem; display:grid; gap:.85rem; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 4px 12px rgba(15,23,42,.035); }
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

.note-discussion{ padding:1.4rem; display:grid; gap:1rem; background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 5px 16px rgba(15,23,42,.035); }
.note-discussion__body{ display:grid; gap:1rem; }
.note-comments{ display:grid; gap:.9rem; }
.note-comment{ border:1px solid #e2e8f0; border-radius:10px; padding:.9rem 1rem; background:#fff; display:grid; gap:.55rem; }
.note-comment__header{ display:flex; justify-content:space-between; gap:.75rem; align-items:flex-start; }
.note-comment__timestamp{ display:block; font-size:.78rem; color:#64748b; margin-top:.15rem; }
.note-comment__delete .btn{ font-size:.75rem; }
.note-comment__body{ white-space:pre-wrap; color:#0f172a; line-height:1.5; }
.note-comment__footer{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-start; }
.note-comment__children{ border-left:2px solid #e2e8f0; margin-left:.6rem; padding-left:.75rem; display:grid; gap:.75rem; }
.note-comment-form{ display:grid; gap:.65rem; }
.note-comment-form textarea{ width:100%; border-radius:.65rem; border:1px solid #d0d7e2; padding:.6rem .75rem; resize:vertical; font-size:.95rem; }
.note-comment-form--new label{ display:grid; gap:.35rem; }
.note-comment-form--inline{ margin-top:.4rem; }
.note-comment-form--inline[hidden]{ display:none; }
.note-comment-form__actions{ display:flex; gap:.4rem; }

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
  const page = document.querySelector('[data-note-page]');
  const grid = document.getElementById('noteViewPhotoGrid');
  const modal = document.getElementById('noteViewPhotoModal');
  const bodyEl = document.getElementById('noteViewPhotoModalBody');
  const openAllBtn = document.getElementById('openAllNotePhotos');
  const shareModal = document.getElementById('noteShareModal');
  const shareForm = document.getElementById('noteShareForm');
  const shareSearch = document.getElementById('noteShareSearch');
  const shareStatus = document.getElementById('noteShareStatus');
  const shareEmpty = document.getElementById('noteShareEmpty');
  const shareTrigger = document.querySelector('[data-share-open]');
  const shareSummary = document.querySelector('[data-share-summary]');
  const shareBadges = document.querySelector('[data-share-list]');
  const csrfField = '<?= CSRF_TOKEN_NAME; ?>';
  const noteId = page ? (page.getAttribute('data-note-id') || '') : '';
  const csrfToken = page ? (page.getAttribute('data-csrf') || '') : '';
  const canShare = page && page.getAttribute('data-can-share') === '1';
  const shareOptionNodes = shareModal ? Array.from(shareModal.querySelectorAll('[data-share-option]')) : [];
  const shareEmptyText = shareSummary ? (shareSummary.getAttribute('data-empty-text') || 'Private') : 'Private';

  const shareState = {
    selected: new Set(),
    options: [],
  };

  const replyForms = Array.from(document.querySelectorAll('[data-reply-form]'));

  function closeReplyForm(form) {
    if (!form) return;
    form.setAttribute('hidden', '');
    const toggle = form.parentElement ? form.parentElement.querySelector('[data-reply-toggle]') : null;
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
  }

  function openReplyForm(form, toggle) {
    if (!form) return;
    replyForms.forEach((other) => {
      if (other !== form) {
        closeReplyForm(other);
      }
    });
    form.removeAttribute('hidden');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
    }
    const textarea = form.querySelector('textarea');
    if (textarea) {
      textarea.focus();
    }
  }

  document.querySelectorAll('[data-reply-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const wrapper = button.closest('.obsidian-comment__footer');
      if (!wrapper) {
        return;
      }
      const form = wrapper.querySelector('[data-reply-form]');
      if (!form) {
        return;
      }
      const isHidden = form.hasAttribute('hidden');
      if (isHidden) {
        openReplyForm(form, button);
      } else {
        closeReplyForm(form);
      }
    });
  });

  document.querySelectorAll('[data-reply-cancel]').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('[data-reply-form]');
      closeReplyForm(form);
    });
  });

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

  if (grid && modal && bodyEl) {
    const buildGallery = () => {
      bodyEl.innerHTML = '';
      const links = grid.querySelectorAll('a');
      links.forEach((link) => {
        const clone = link.cloneNode(true);
        clone.removeAttribute('target');
        clone.removeAttribute('rel');
        bodyEl.appendChild(clone);
      });
    };

    if (openAllBtn) {
      openAllBtn.addEventListener('click', (event) => {
        event.preventDefault();
        buildGallery();
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        lockScroll();
      });
    }

    modal.querySelectorAll('[data-close-note-view]').forEach((btn) => {
      btn.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        unlockScroll();
      });
    });
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
            if (!data || !data.ok) {
              input.checked = !input.checked;
            }
          })
          .catch(() => {
            input.checked = !input.checked;
          });
      });
    });
  }

  function renderShareBadges(target, shares) {
    if (!target) return;
    target.innerHTML = '';
    if (!shares.length) {
      if (target.dataset.shareEmpty !== undefined) {
        target.textContent = shareEmptyText;
        target.className = 'obsidian-muted';
      } else {
        const pill = document.createElement('span');
        pill.className = 'obsidian-pill is-muted';
        pill.textContent = shareEmptyText;
        target.appendChild(pill);
      }
      return;
    }
    shares.forEach((entry) => {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill';
      pill.textContent = entry.label || entry;
      target.appendChild(pill);
    });
  }

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

  function openShareModal() {
    if (!shareModal) return;
    shareModal.classList.remove('hidden');
    shareModal.setAttribute('aria-hidden', 'false');
    lockScroll();
    const first = shareModal.querySelector('input[type="checkbox"]');
    if (first) first.focus();
  }

  function closeShareModal() {
    if (!shareModal) return;
    shareModal.classList.add('hidden');
    shareModal.setAttribute('aria-hidden', 'true');
    unlockScroll();
  }

  if (shareModal) {
    shareModal.querySelectorAll('[data-modal-close]').forEach((btn) => {
      btn.addEventListener('click', closeShareModal);
    });
  }

  if (shareTrigger) {
    shareTrigger.addEventListener('click', (event) => {
      event.preventDefault();
      openShareModal();
    });
  }

  if (shareSearch && shareState.options.length) {
    shareSearch.addEventListener('input', () => {
      const query = shareSearch.value.toLowerCase();
      let matches = 0;
      shareState.options.forEach((option) => {
        if (!option.element) return;
        const match = option.label.includes(query);
        option.element.style.display = match ? '' : 'none';
        if (match) matches += 1;
      });
      if (shareEmpty) {
        shareEmpty.hidden = matches !== 0;
      }
    });
  }

  function syncShareOptions(selectedIds) {
    shareOptionNodes.forEach((option) => {
      const checkbox = option.querySelector('input');
      if (!checkbox) return;
      const uid = Number(checkbox.value || '0');
      checkbox.checked = selectedIds.includes(uid);
      if (checkbox.disabled) {
        checkbox.checked = true;
      }
    });
  }

  if (shareForm) {
    shareForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(shareForm);
      fetch(`view.php?id=${noteId}`, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then((res) => {
        if (!res.ok) throw new Error('Request failed');
        return res.json();
      }).then((data) => {
        if (data && data.ok) {
          const shares = data.shares || [];
          const ids = shares.map((entry) => Number(entry.id || entry));
          syncShareOptions(ids);
          renderShareBadges(shareBadges, shares);
          renderShareBadges(shareSummary, shares);
          if (shareStatus) {
            shareStatus.textContent = 'Access updated';
            shareStatus.classList.remove('is-error');
          }
          setTimeout(closeShareModal, 350);
        } else {
          throw new Error('Bad response');
        }
      }).catch(() => {
        if (shareStatus) {
          shareStatus.textContent = 'Could not update shares.';
          shareStatus.classList.add('is-error');
        }
      });
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeShareModal();
      if (modal) {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
      }
      unlockScroll();
    }
  });
});
</script>
