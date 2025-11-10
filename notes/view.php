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
.obsidian-shell{position:relative;background:#fff;color:#0f172a;border-radius:20px;padding:1.4rem 1.6rem;margin-bottom:1.5rem;border:1px solid #e2e8f0;box-shadow:0 18px 40px rgba(15,23,42,.08);}
.obsidian-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.65rem;font-weight:700;color:#0f172a;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;color:#64748b;margin-bottom:.2rem;display:block;}
.obsidian-header__actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;}
.btn.obsidian-primary{background:#2563eb;border:none;color:#fff;border-radius:999px;padding:.5rem 1.2rem;font-weight:600;box-shadow:0 10px 20px rgba(37,99,235,.18);cursor:pointer;}
.btn.obsidian-btn{background:#e0e7ff;border:1px solid #c7d2fe;color:#1d4ed8;border-radius:999px;padding:.45rem .95rem;font-weight:500;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost{background:#fff;border:1px solid #cbd5f5;color:#1e293b;border-radius:999px;padding:.45rem .95rem;font-weight:500;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost.small{padding:.35rem .75rem;font-size:.8rem;}
.btn.obsidian-btn:hover{background:#c7d2fe;border-color:#94a3ff;color:#1e3a8a;}
.btn.obsidian-btn--ghost:hover{background:#f8fafc;border-color:#94a3b8;color:#0f172a;}
.obsidian-layout{display:grid;gap:1.25rem;grid-template-columns:minmax(0,1fr) 300px;align-items:start;}
.obsidian-sidebar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1.1rem;display:grid;gap:1.1rem;}
.obsidian-main{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1.1rem;display:grid;gap:1.25rem;}
.obsidian-panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:.95rem 1rem;display:grid;gap:.75rem;}
.obsidian-panel__header{display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;flex-wrap:wrap;}
.obsidian-panel__header h2{margin:0;font-size:1rem;color:#0f172a;}
.obsidian-panel__hint{margin:.2rem 0 0;font-size:.82rem;color:#64748b;}
.obsidian-detail{display:flex;gap:.9rem;align-items:flex-start;}
.obsidian-detail__icon{width:52px;height:52px;border-radius:15px;background:#e0e7ff;display:grid;place-items:center;font-size:1.4rem;color:#1d4ed8;}
.obsidian-detail__content{display:grid;gap:.55rem;}
.obsidian-detail__meta{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;font-size:.82rem;color:#475569;}
.obsidian-detail__shares{display:flex;gap:.3rem;flex-wrap:wrap;}
.obsidian-detail__tags{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-detail__count{background:#edf2f7;border-radius:999px;padding:.25rem .55rem;font-size:.75rem;color:#475569;font-weight:500;}
.obsidian-status{display:inline-flex;align-items:center;border-radius:999px;padding:.25rem .55rem;font-size:.75rem;font-weight:600;}
.obsidian-status--idea{background:#e0f2fe;color:#0369a1;}
.obsidian-status--in_progress{background:#e0e7ff;color:#4338ca;}
.obsidian-status--review{background:#ede9fe;color:#6d28d9;}
.obsidian-status--blocked{background:#fef3c7;color:#b45309;}
.obsidian-status--complete{background:#dcfce7;color:#047857;}
.obsidian-status--archived{background:#e2e8f0;color:#475569;}
.obsidian-pill{display:inline-flex;align-items:center;background:#e0e7ff;border-radius:999px;padding:.25rem .6rem;font-size:.75rem;color:#1d4ed8;font-weight:500;}
.obsidian-pill.is-muted{background:#edf2f7;color:#64748b;}
.obsidian-tag{display:inline-flex;align-items:center;gap:.3rem;background:#e0e7ff;border-radius:999px;padding:.25rem .6rem;font-size:.75rem;color:#1d4ed8;}
.obsidian-tag::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--tag-color,#6366f1);}
.obsidian-reader{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1.15rem;display:grid;gap:1.1rem;}
.obsidian-blocks{display:grid;gap:1rem;}
.obsidian-block__heading{margin:0;color:#0f172a;}
.obsidian-block__heading--h1{font-size:1.6rem;}
.obsidian-block__heading--h2{font-size:1.35rem;}
.obsidian-block__heading--h3{font-size:1.15rem;}
.obsidian-block__text{margin:0;color:#1f2937;font-size:1rem;line-height:1.65;}
.obsidian-block__todo{display:flex;gap:.55rem;align-items:flex-start;color:#1f2937;font-size:.98rem;line-height:1.55;}
.obsidian-block__todo input{margin-top:.25rem;width:1rem;height:1rem;}
.obsidian-block__list{margin:0;padding-left:1.25rem;display:grid;gap:.4rem;color:#1f2937;line-height:1.6;}
.obsidian-block__quote{margin:0;padding:.9rem 1.1rem;border-left:3px solid #2563eb;background:#eff6ff;border-radius:0 .85rem .85rem 0;color:#1e3a8a;}
.obsidian-block__callout{display:flex;gap:.75rem;padding:.9rem;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid var(--callout-accent,#2563eb);border-radius:12px;color:#1f2937;}
.obsidian-block__callout-icon{font-size:1.25rem;}
.obsidian-block__divider{height:2px;background:linear-gradient(90deg,#cbd5f5,#e2e8f0);border-radius:999px;}
.obsidian-empty{margin:0;color:#64748b;}
.obsidian-properties{margin:0;padding:0;display:grid;gap:.7rem;}
.obsidian-properties__item{display:grid;gap:.3rem;}
.obsidian-properties__item dt{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;}
.obsidian-properties__item dd{margin:0;font-size:.92rem;color:#0f172a;}
.obsidian-field-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;display:block;margin-bottom:.3rem;}
.obsidian-detail-grid{display:grid;gap:.65rem;}
.obsidian-muted{color:#64748b;}
.obsidian-overdue{color:#b91c1c;font-weight:600;}
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.25rem .55rem;border-radius:999px;font-size:.7rem;font-weight:600;}
.badge--muted{background:#e2e8f0;color:#475569;}
.badge--blue{background:#dbeafe;color:#1d4ed8;}
.badge--indigo{background:#e0e7ff;color:#4338ca;}
.badge--purple{background:#ede9fe;color:#7c3aed;}
.badge--orange{background:#fef3c7;color:#b45309;}
.badge--green{background:#dcfce7;color:#047857;}
.badge--slate{background:#f1f5f9;color:#475569;}
.badge--danger{background:#fee2e2;color:#b91c1c;}
.badge--amber{background:#fef3c7;color:#92400e;}
.badge--teal{background:#ccfbf1;color:#0f766e;}
.obsidian-attachments{display:grid;gap:.55rem;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));}
.obsidian-attachment{display:block;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;}
.obsidian-attachment img{width:100%;height:100%;object-fit:cover;aspect-ratio:4/3;}
.obsidian-attachment--empty{display:grid;place-items:center;min-height:110px;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5f5;}
.obsidian-comments{display:grid;gap:.85rem;}
.obsidian-comment{border:1px solid #e2e8f0;border-radius:12px;padding:.85rem 1rem;background:#fff;display:grid;gap:.65rem;box-shadow:0 10px 24px rgba(15,23,42,.04);}
.obsidian-comment__header{display:flex;justify-content:space-between;gap:.65rem;align-items:flex-start;flex-wrap:wrap;}
.obsidian-comment__timestamp{display:block;font-size:.75rem;color:#94a3b8;margin-top:.15rem;}
.obsidian-comment__body{white-space:pre-wrap;color:#1f2937;line-height:1.55;}
.obsidian-comment__footer{display:flex;gap:.55rem;flex-wrap:wrap;align-items:flex-start;}
.obsidian-comment__children{border-left:2px solid #e2e8f0;margin-left:.6rem;padding-left:.7rem;display:grid;gap:.65rem;}
.obsidian-comment-form{display:grid;gap:.65rem;}
.obsidian-comment-form textarea{width:100%;border-radius:.75rem;border:1px solid #cbd5f5;padding:.6rem .75rem;background:#fff;color:#0f172a;resize:vertical;font-size:.95rem;}
.obsidian-comment-form--inline{margin-top:.45rem;}
.obsidian-comment-form--inline[hidden]{display:none;}
.obsidian-comment-actions{display:flex;gap:.5rem;flex-wrap:wrap;}
.obsidian-photo-modal{position:fixed;inset:0;z-index:60;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.25);backdrop-filter:blur(8px);transition:opacity .2s ease;}
.obsidian-photo-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-photo-modal__overlay{position:absolute;inset:0;}
.obsidian-photo-modal__dialog{position:relative;z-index:1;width:min(960px,100%);background:#fff;border:1px solid #e2e8f0;border-radius:18px;display:grid;gap:0;box-shadow:0 26px 52px rgba(15,23,42,.12);}
.obsidian-photo-modal__header{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1.1rem;border-bottom:1px solid #e2e8f0;}
.obsidian-photo-modal__header h3{margin:0;color:#0f172a;font-size:1.05rem;}
.obsidian-photo-modal__close{background:none;border:none;color:#64748b;font-size:1.6rem;cursor:pointer;}
.obsidian-photo-modal__body{padding:.9rem;max-height:70vh;overflow:auto;display:grid;gap:.65rem;}
.obsidian-photo-modal__body a{display:block;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;}
.obsidian-photo-modal__body img{width:100%;height:100%;object-fit:cover;}
.obsidian-modal{position:fixed;inset:0;z-index:70;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.25);backdrop-filter:blur(6px);transition:opacity .2s ease;}
.obsidian-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-modal__overlay{position:absolute;inset:0;}
.obsidian-modal__dialog{position:relative;z-index:1;width:min(520px,100%);background:#fff;border:1px solid #e2e8f0;border-radius:16px;display:grid;gap:.6rem;box-shadow:0 22px 44px rgba(15,23,42,.12);}
.obsidian-modal__dialog--share{padding:0;}
.obsidian-modal__header{display:flex;justify-content:space-between;align-items:flex-start;padding:.9rem 1.05rem;border-bottom:1px solid #e2e8f0;gap:.6rem;flex-wrap:wrap;}
.obsidian-modal__header h3{margin:0;color:#0f172a;font-size:1.05rem;}
.obsidian-modal__subtitle{margin:.2rem 0 0;color:#475569;font-size:.85rem;}
.obsidian-modal__close{background:none;border:none;color:#64748b;font-size:1.4rem;cursor:pointer;}
.obsidian-modal__form{display:grid;gap:0;}
.obsidian-modal__body{padding:.9rem 1.05rem;display:grid;gap:.75rem;max-height:340px;overflow:auto;}
.obsidian-modal__search input{width:100%;background:#fff;border:1px solid #cbd5f5;border-radius:.75rem;padding:.55rem .75rem;color:#0f172a;font-size:.95rem;}
.obsidian-modal__options{display:grid;gap:.55rem;}
.obsidian-modal__option{display:flex;gap:.55rem;align-items:center;padding:.55rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;cursor:pointer;transition:border-color .2s ease,background .2s ease;}
.obsidian-modal__option:hover{border-color:#2563eb;}
.obsidian-modal__option input{width:1rem;height:1rem;}
.obsidian-modal__badge{margin-left:auto;font-size:.72rem;color:#64748b;background:#edf2f7;border-radius:999px;padding:.2rem .5rem;}
.obsidian-modal__empty{margin:0;font-size:.8rem;color:#64748b;}
.obsidian-modal__footer{padding:.85rem 1.05rem;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;}
.obsidian-modal__status{font-size:.8rem;color:#64748b;min-height:1.1rem;}
.obsidian-modal__status.is-error{color:#b91c1c;}
.flash{border-radius:10px;padding:.75rem .95rem;margin-bottom:1rem;font-weight:500;}
.flash-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;}
.visually-hidden{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;}
@media (max-width:1080px){.obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar,.obsidian-main{grid-column:1 / -1;}}
@media (max-width:720px){.obsidian-shell{padding:1.25rem;} .obsidian-header__titles h1{font-size:1.45rem;} .obsidian-detail{flex-direction:column;align-items:flex-start;} .obsidian-detail__icon{width:46px;height:46px;font-size:1.2rem;} }
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
