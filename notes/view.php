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
<section class="obsidian-shell obsidian-shell--viewer" data-theme="obsidian" data-note-page data-note-id="<?php echo  (int)$id; ?>" data-csrf="<?php echo  sanitize($csrfToken); ?>" data-can-share="<?php echo  $canShare ? '1' : '0'; ?>" data-share-config="<?php echo  $shareConfigAttr; ?>">
  <header class="obsidian-header obsidian-header--viewer">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Note detail</span>
      <div class="obsidian-detail">
        <span class="obsidian-detail__icon"><?php echo  sanitize($meta['icon'] ?: '🗒️'); ?></span>
        <div class="obsidian-detail__content">
          <h1><?php echo  sanitize($note['title'] ?: 'Untitled'); ?></h1>
          <div class="obsidian-detail__meta">
            <span class="obsidian-status obsidian-status--<?php echo  sanitize($statusSlug); ?>"><?php echo  sanitize($statusLabel); ?></span>
            <?php if ($noteDateFormatted): ?>
              <span class="obsidian-detail__timestamp"><?php echo  sanitize($noteDateFormatted); ?></span>
            <?php endif; ?>
            <?php if ($commentCount): ?>
              <span class="obsidian-detail__count">💬 <?php echo  (int)$commentCount; ?></span>
            <?php endif; ?>
            <?php if (array_filter($photos)): ?>
              <span class="obsidian-detail__count">📸 <?php echo  count(array_filter($photos)); ?></span>
            <?php endif; ?>
          </div>
          <div class="obsidian-detail__shares" data-share-list>
            <?php if ($shareDetails): ?>
              <?php foreach ($shareDetails as $share): ?>
                <span class="obsidian-pill"><?php echo  sanitize($share['label']); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="obsidian-pill is-muted" data-share-empty>Private</span>
            <?php endif; ?>
          </div>
          <?php if ($tags): ?>
          <div class="obsidian-detail__tags">
            <?php foreach ($tags as $tag): ?>
              <span class="obsidian-tag" style="--tag-color: <?php echo  sanitize($tag['color'] ?? notes_random_tag_color()); ?>"><?php echo  sanitize($tag['label']); ?></span>
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
        <a class="btn obsidian-primary" href="edit.php?id=<?php echo  (int)$note['id']; ?>">Edit note</a>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo  sanitize(implode(' ', $errors)); ?></div>
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
            <div class="obsidian-block obsidian-block--<?php echo  sanitize($type); ?>">
              <?php switch ($type) {
                case 'heading1': ?>
                  <h2 class="obsidian-block__heading obsidian-block__heading--h1"><?php echo  nl2br(sanitize($text)); ?></h2>
                <?php break;
                case 'heading2': ?>
                  <h3 class="obsidian-block__heading obsidian-block__heading--h2"><?php echo  nl2br(sanitize($text)); ?></h3>
                <?php break;
                case 'heading3': ?>
                  <h4 class="obsidian-block__heading obsidian-block__heading--h3"><?php echo  nl2br(sanitize($text)); ?></h4>
                <?php break;
                case 'todo': ?>
                  <label class="obsidian-block__todo">
                    <input type="checkbox" value="1" data-block-toggle="<?php echo  sanitize($uid); ?>" <?php echo  $checked ? 'checked' : ''; ?><?php echo  $canEdit ? '' : ' disabled'; ?>>
                    <span><?php echo  nl2br(sanitize($text)); ?></span>
                  </label>
                <?php break;
                case 'bulleted': ?>
                  <ul class="obsidian-block__list obsidian-block__list--bulleted">
                    <?php foreach ($items as $item): ?>
                      <li><?php echo  nl2br(sanitize((string)$item)); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php break;
                case 'numbered': ?>
                  <ol class="obsidian-block__list obsidian-block__list--numbered">
                    <?php foreach ($items as $item): ?>
                      <li><?php echo  nl2br(sanitize((string)$item)); ?></li>
                    <?php endforeach; ?>
                  </ol>
                <?php break;
                case 'quote': ?>
                  <blockquote class="obsidian-block__quote"><?php echo  nl2br(sanitize($text)); ?></blockquote>
                <?php break;
                case 'callout': ?>
                  <div class="obsidian-block__callout"<?php echo  $calloutColor ? ' style="--callout-accent:' . sanitize($calloutColor) . ';"' : ''; ?>>
                    <div class="obsidian-block__callout-icon" aria-hidden="true"><?php echo  $calloutIcon ? sanitize($calloutIcon) : '💡'; ?></div>
                    <div><?php echo  nl2br(sanitize($text)); ?></div>
                  </div>
                <?php break;
                case 'divider': ?>
                  <div class="obsidian-block__divider" role="presentation"></div>
                <?php break;
                default: ?>
                  <p class="obsidian-block__text"><?php echo  nl2br(sanitize($text)); ?></p>
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
          <span class="obsidian-pill is-muted"><?php echo  (int)$commentCount; ?> replies</span>
        </header>
        <div class="obsidian-comments">
          <?php if (!$commentThreads): ?>
            <p class="obsidian-empty">No replies yet.</p>
          <?php else: ?>
            <?php
            $renderComment = static function (array $comment, callable $renderComment) use ($csrfToken, $note) {
                ?>
                <article class="obsidian-comment" id="comment-<?php echo  (int)$comment['id']; ?>">
                  <header class="obsidian-comment__header">
                    <div>
                      <strong><?php echo  sanitize($comment['author_label']); ?></strong>
                      <span class="obsidian-comment__timestamp"><?php echo  sanitize(substr((string)($comment['created_at'] ?? ''), 0, 16)); ?></span>
                    </div>
                    <?php if (!empty($comment['can_delete'])): ?>
                      <form method="post" class="obsidian-comment__delete" onsubmit="return confirm('Delete this reply?');">
                        <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
                        <button class="btn obsidian-btn--ghost small" type="submit" name="delete_comment" value="<?php echo  (int)$comment['id']; ?>">Delete</button>
                      </form>
                    <?php endif; ?>
                  </header>
                  <div class="obsidian-comment__body"><?php echo  nl2br(sanitize($comment['body'] ?? '')); ?></div>
                  <footer class="obsidian-comment__footer">
                    <button class="btn obsidian-btn--ghost small" type="button" data-reply-toggle>Reply</button>
                    <form method="post" class="obsidian-comment-form obsidian-comment-form--inline" data-reply-form hidden>
                      <textarea name="body" rows="3" required></textarea>
                      <input type="hidden" name="parent_id" value="<?php echo  (int)$comment['id']; ?>">
                      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
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
        </div>

        <form method="post" class="obsidian-comment-form obsidian-comment-form--new">
          <label>
            <span class="obsidian-field-label">Add a reply</span>
            <textarea name="body" rows="4" required><?php echo  sanitize($_POST['body'] ?? ''); ?></textarea>
          </label>
          <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
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
            <dt><?php echo  sanitize($label); ?></dt>
            <dd><?php echo  $value; ?></dd>
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
            <span><?php echo  sanitize($ownerLabel); ?></span>
          </div>
          <div>
            <span class="obsidian-field-label">Shared with</span>
            <div class="obsidian-detail__shares" data-share-summary data-empty-text="Private">
              <?php if ($shareDetails): ?>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="obsidian-pill"><?php echo  sanitize($share['label']); ?></span>
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
              <a href="<?php echo  sanitize($p['url']); ?>" class="obsidian-attachment" target="_blank" rel="noopener">
                <img src="<?php echo  sanitize($p['url']); ?>" alt="Note photo <?php echo  $i; ?>" loading="lazy" decoding="async">
              </a>
            <?php else: ?>
              <div class="obsidian-attachment obsidian-attachment--empty">Slot <?php echo  $i; ?></div>
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
      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
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
            <label class="obsidian-modal__option" data-share-option data-label="<?php echo  htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="checkbox" name="shared_ids[]" value="<?php echo  $uid; ?>" <?php echo  $checked ? 'checked' : ''; ?> <?php echo  $isOwner ? 'disabled' : ''; ?>>
              <span><?php echo  sanitize($label); ?></span>
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
:root[data-theme="obsidian"] {
  color-scheme: only light;
  --surface-bg: #f6f7fb;
  --surface-panel: #ffffff;
  --surface-border: #e2e8f0;
  --surface-accent: #2563eb;
  --surface-accent-soft: #eff6ff;
  --surface-muted: #94a3b8;
  --surface-strong: #0f172a;
  --surface-shadow: rgba(15, 23, 42, 0.08);
}

.obsidian-shell {
  background: var(--surface-bg);
  border-radius: 28px;
  padding: 1.8rem;
  border: 1px solid rgba(148,163,184,0.2);
  box-shadow: 0 34px 68px var(--surface-shadow);
  margin-bottom: 2rem;
}

.obsidian-header {
  background: var(--surface-panel);
  border-radius: 22px;
  border: 1px solid var(--surface-border);
  padding: 1.3rem 1.5rem;
  display: flex;
  justify-content: space-between;
  gap: 1.2rem;
  align-items: center;
  box-shadow: 0 24px 52px var(--surface-shadow);
}

.obsidian-header__titles {
  display: grid;
  gap: 0.5rem;
}

.obsidian-header__eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-size: 0.72rem;
  color: var(--surface-muted);
}

.obsidian-detail {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
}

.obsidian-detail__icon {
  width: 60px;
  height: 60px;
  border-radius: 18px;
  background: rgba(37,99,235,0.15);
  display: grid;
  place-items: center;
  font-size: 1.6rem;
  color: var(--surface-accent);
}

.obsidian-detail__content {
  display: grid;
  gap: 0.6rem;
}

.obsidian-detail__meta {
  display: flex;
  gap: 0.45rem;
  flex-wrap: wrap;
  font-size: 0.82rem;
  color: var(--surface-muted);
}

.obsidian-detail__meta time,
.obsidian-detail__count {
  background: rgba(226,232,240,0.9);
  border-radius: 999px;
  padding: 0.25rem 0.6rem;
  color: var(--surface-strong);
  font-weight: 500;
}

.obsidian-status {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  font-size: 0.76rem;
  font-weight: 600;
}

.obsidian-status--idea { background: #dbeafe; color: #1d4ed8; }
.obsidian-status--in_progress { background: #e0e7ff; color: #4338ca; }
.obsidian-status--review { background: #ede9fe; color: #6d28d9; }
.obsidian-status--blocked { background: #fef3c7; color: #b45309; }
.obsidian-status--complete { background: #dcfce7; color: #047857; }
.obsidian-status--archived { background: #f1f5f9; color: #475569; }

.obsidian-detail__shares,
.obsidian-detail__tags {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.obsidian-pill {
  display: inline-flex;
  align-items: center;
  background: rgba(226,232,240,0.9);
  border-radius: 999px;
  padding: 0.25rem 0.6rem;
  font-size: 0.72rem;
  color: var(--surface-strong);
}

.obsidian-pill.is-muted {
  background: rgba(148,163,184,0.2);
  color: var(--surface-muted);
}

.obsidian-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  background: rgba(37,99,235,0.12);
  border-radius: 999px;
  padding: 0.25rem 0.6rem;
  font-size: 0.72rem;
  color: var(--surface-accent);
}

.obsidian-tag::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--tag-color, var(--surface-accent));
}

.obsidian-header__actions {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.obsidian-header__actions .btn {
  border-radius: 999px;
  padding: 0.5rem 1.1rem;
  font-weight: 600;
  font-size: 0.9rem;
  border: 1px solid transparent;
  cursor: pointer;
  text-decoration: none;
  transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.obsidian-btn--ghost {
  background: #fff;
  border-color: rgba(148,163,184,0.45);
  color: var(--surface-strong);
}

.obsidian-btn--ghost:hover {
  border-color: var(--surface-accent);
  color: var(--surface-accent);
}

.obsidian-btn {
  background: rgba(37,99,235,0.12);
  color: var(--surface-accent);
}

.obsidian-primary {
  background: var(--surface-accent);
  color: #fff;
  box-shadow: 0 18px 38px rgba(37,99,235,0.26);
}

.obsidian-header__actions .btn:hover {
  transform: translateY(-1px);
}

.obsidian-layout {
  margin-top: 1.2rem;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 1.4rem;
}

.obsidian-main {
  background: var(--surface-panel);
  border-radius: 20px;
  border: 1px solid var(--surface-border);
  padding: 1.3rem;
  box-shadow: 0 20px 42px var(--surface-shadow);
  display: grid;
  gap: 1.2rem;
}

.obsidian-reader {
  background: rgba(248,250,252,0.95);
  border-radius: 18px;
  border: 1px solid rgba(226,232,240,0.9);
  padding: 1.2rem 1.3rem;
  display: grid;
  gap: 1.1rem;
}

.obsidian-blocks {
  display: grid;
  gap: 1.05rem;
}

.obsidian-block {
  display: grid;
  gap: 0.75rem;
  font-size: 1rem;
  color: rgba(15,23,42,0.9);
  line-height: 1.65;
}

.obsidian-block__heading--h1 { font-size: 1.6rem; color: var(--surface-strong); }
.obsidian-block__heading--h2 { font-size: 1.35rem; color: var(--surface-strong); }
.obsidian-block__heading--h3 { font-size: 1.15rem; color: var(--surface-strong); }

.obsidian-block__text,
.obsidian-block__todo,
.obsidian-block__list {
  margin: 0;
}

.obsidian-block__todo {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
}

.obsidian-block__todo input {
  margin-top: 0.25rem;
  width: 1rem;
  height: 1rem;
}

.obsidian-block__list {
  padding-left: 1.35rem;
  display: grid;
  gap: 0.45rem;
}

.obsidian-block__quote {
  margin: 0;
  padding: 1rem 1.2rem;
  background: var(--surface-accent-soft);
  border-left: 4px solid var(--surface-accent);
  border-radius: 0 14px 14px 0;
  color: #1d4ed8;
}

.obsidian-block__callout {
  display: flex;
  gap: 0.85rem;
  padding: 1rem;
  background: rgba(248,250,252,0.92);
  border: 1px solid rgba(226,232,240,0.9);
  border-left: 4px solid var(--callout-accent, var(--surface-accent));
  border-radius: 16px;
}

.obsidian-block__divider {
  height: 2px;
  background: linear-gradient(90deg, rgba(203,213,225,0.8), rgba(226,232,240,0.8));
  border-radius: 999px;
}

.obsidian-sidebar {
  background: var(--surface-panel);
  border-radius: 20px;
  border: 1px solid var(--surface-border);
  padding: 1.2rem;
  box-shadow: 0 20px 42px var(--surface-shadow);
  display: grid;
  gap: 1.1rem;
}

.obsidian-panel {
  background: rgba(248,250,252,0.95);
  border-radius: 18px;
  border: 1px solid rgba(226,232,240,0.9);
  padding: 1.05rem 1.1rem;
  display: grid;
  gap: 0.8rem;
}

.obsidian-panel__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.obsidian-panel__header h2 {
  margin: 0;
  font-size: 1rem;
  color: var(--surface-strong);
}

.obsidian-panel__hint {
  margin: 0;
  font-size: 0.82rem;
  color: var(--surface-muted);
}

.obsidian-properties {
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.75rem;
}

.obsidian-properties__item {
  display: grid;
  gap: 0.35rem;
}

.obsidian-properties__item dt {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--surface-muted);
}

.obsidian-properties__item dd {
  margin: 0;
  font-size: 0.92rem;
  color: var(--surface-strong);
}

.obsidian-detail-grid {
  display: grid;
  gap: 0.7rem;
}

.obsidian-muted { color: var(--surface-muted); }
.obsidian-overdue { color: #b91c1c; font-weight: 600; }

.obsidian-attachments {
  display: grid;
  gap: 0.6rem;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
}

.obsidian-attachment {
  display: block;
  border-radius: 14px;
  overflow: hidden;
  border: 1px solid rgba(226,232,240,0.9);
  background: #fff;
  box-shadow: 0 12px 28px rgba(15,23,42,0.06);
}

.obsidian-attachment img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  aspect-ratio: 4 / 3;
}

.obsidian-attachment--empty {
  display: grid;
  place-items: center;
  min-height: 120px;
  color: var(--surface-muted);
  background: rgba(248,250,252,0.9);
  border: 1px dashed rgba(148,163,184,0.4);
}

.obsidian-comments {
  display: grid;
  gap: 0.9rem;
}

.obsidian-comment {
  background: #fff;
  border: 1px solid rgba(226,232,240,0.9);
  border-radius: 14px;
  padding: 0.9rem 1rem;
  display: grid;
  gap: 0.75rem;
  box-shadow: 0 16px 36px rgba(15,23,42,0.08);
}

.obsidian-comment__header {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
  align-items: flex-start;
  flex-wrap: wrap;
}

.obsidian-comment__timestamp {
  font-size: 0.75rem;
  color: var(--surface-muted);
}

.obsidian-comment__body {
  white-space: pre-wrap;
  color: rgba(15,23,42,0.88);
  line-height: 1.6;
}

.obsidian-comment__footer {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.obsidian-comment__children {
  border-left: 2px solid rgba(226,232,240,0.9);
  margin-left: 0.7rem;
  padding-left: 0.7rem;
  display: grid;
  gap: 0.75rem;
}

.obsidian-comment-form {
  display: grid;
  gap: 0.65rem;
}

.obsidian-comment-form textarea {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(148,163,184,0.45);
  padding: 0.6rem 0.85rem;
  font-size: 0.95rem;
  color: var(--surface-strong);
  resize: vertical;
}

.obsidian-comment-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.obsidian-empty {
  margin: 0;
  color: var(--surface-muted);
  text-align: center;
}

.obsidian-photo-modal,
.obsidian-modal,
.share-modal {
  position: fixed;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 2rem;
  background: rgba(15,23,42,0.3);
  backdrop-filter: blur(6px);
  transition: opacity 0.2s ease;
  z-index: 70;
}

.obsidian-photo-modal.hidden,
.obsidian-modal.hidden,
.share-modal.hidden {
  opacity: 0;
  pointer-events: none;
}

.obsidian-photo-modal__overlay,
.obsidian-modal__overlay,
.share-modal__overlay {
  position: absolute;
  inset: 0;
}

.obsidian-photo-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(960px, 100%);
  background: #fff;
  border-radius: 20px;
  border: 1px solid rgba(226,232,240,0.9);
  box-shadow: 0 48px 98px rgba(15,23,42,0.18);
  display: grid;
  gap: 0;
}

.obsidian-photo-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.1rem;
  border-bottom: 1px solid rgba(226,232,240,0.9);
}

.obsidian-photo-modal__close {
  background: none;
  border: none;
  color: var(--surface-muted);
  font-size: 1.6rem;
  cursor: pointer;
}

.obsidian-photo-modal__body {
  padding: 1rem;
  max-height: 70vh;
  overflow: auto;
  display: grid;
  gap: 0.7rem;
}

.obsidian-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(520px, 100%);
  background: #fff;
  border-radius: 22px;
  border: 1px solid rgba(226,232,240,0.9);
  box-shadow: 0 44px 92px rgba(15,23,42,0.2);
  padding: 1.3rem;
  display: grid;
  gap: 0.9rem;
}

.share-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(480px, 100%);
  background: #fff;
  border-radius: 22px;
  border: 1px solid rgba(226,232,240,0.9);
  box-shadow: 0 44px 92px rgba(15,23,42,0.18);
  padding: 1.3rem;
  display: grid;
  gap: 0.85rem;
}

.share-modal__header,
.obsidian-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.7rem;
}

.share-modal__subtitle,
.obsidian-modal__subtitle {
  margin: 0.2rem 0 0;
  color: var(--surface-muted);
  font-size: 0.82rem;
}

.share-modal__close,
.obsidian-modal__close {
  background: rgba(226,232,240,0.8);
  border: none;
  border-radius: 50%;
  width: 34px;
  height: 34px;
  font-size: 1.15rem;
  cursor: pointer;
}

.share-modal__form,
.obsidian-modal__form {
  display: grid;
  gap: 0.85rem;
}

.share-modal__body,
.obsidian-modal__body {
  display: grid;
  gap: 0.55rem;
  max-height: 320px;
  overflow: auto;
  border-radius: 12px;
  border: 1px solid rgba(226,232,240,0.85);
  padding: 0.6rem;
}

.share-modal__option,
.obsidian-modal__option {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  background: rgba(248,250,252,0.95);
  border-radius: 10px;
  padding: 0.5rem 0.65rem;
  font-size: 0.85rem;
  color: var(--surface-strong);
}

.share-modal__footer,
.obsidian-modal__footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.share-modal__status,
.obsidian-modal__status {
  font-size: 0.78rem;
  color: var(--surface-muted);
}

.share-modal__status.is-error,
.obsidian-modal__status.is-error {
  color: #b91c1c;
}

@media (max-width: 1080px) {
  .obsidian-layout {
    grid-template-columns: minmax(0, 1fr);
  }
  .obsidian-sidebar {
    order: -1;
  }
}

@media (max-width: 720px) {
  .obsidian-shell {
    padding: 1.2rem;
  }
  .obsidian-header {
    flex-direction: column;
    align-items: flex-start;
  }
  .obsidian-detail {
    flex-direction: column;
    align-items: flex-start;
  }
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
  const csrfField = '<?php echo  CSRF_TOKEN_NAME; ?>';
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
