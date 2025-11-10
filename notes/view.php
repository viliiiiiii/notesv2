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

$canEdit         = notes_can_edit($note);
$photos          = notes_fetch_photos($id);
$shareDetails    = notes_get_share_details($id);
$commentsEnabled = notes_comments_table_exists();
$commentThreads  = $commentsEnabled ? notes_fetch_comment_threads($id) : [];
$commentCount    = $commentsEnabled ? notes_comment_count($id) : 0;
$meta            = notes_fetch_page_meta($id);
$tags            = notes_fetch_note_tags($id);
$blocks          = notes_fetch_blocks($id);

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
include __DIR__ . '/../includes/header.php';
?>
<section class="note-page note-page--view" data-note-page data-note-id="<?= (int)$id; ?>" data-csrf="<?= sanitize($csrfToken); ?>">
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
              <?php if (!empty($shareDetails)): ?>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="badge badge--muted"><?= sanitize($share['label']); ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
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
            <div class="note-detail-value">
              <?php if ($shareDetails): ?>
                <?php foreach ($shareDetails as $share): ?>
                  <span class="badge badge--muted"><?= sanitize($share['label']); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="muted">Private</span>
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

<style>
.note-page{ display:grid; gap:1.5rem; }
.note-hero{ position:relative; border-radius:18px; overflow:hidden; background:linear-gradient(135deg,#eef2ff,#e0f2fe); box-shadow:0 20px 45px rgba(15,23,42,.12); }
.note-hero.has-cover{ background:#0f172a; color:#fff; }
.note-hero__cover{ position:absolute; inset:0; background-size:cover; background-position:center; opacity:.35; }
.note-hero__inner{ position:relative; padding:2.5rem; display:flex; flex-direction:column; gap:1rem; }
.note-hero__top{ display:flex; align-items:flex-start; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; }
.note-hero__identity{ display:flex; gap:1.25rem; align-items:center; }
.note-icon{ width:70px; height:70px; border-radius:22px; display:grid; place-items:center; font-size:2rem; background:rgba(255,255,255,.85); box-shadow:0 12px 28px rgba(15,23,42,.18); }
.note-hero.has-cover .note-icon{ background:rgba(15,23,42,.6); color:#fff; }
.note-hero h1{ margin:0 0 .5rem; font-size:2rem; line-height:1.2; }
.note-meta{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; font-size:.9rem; }
.note-meta__date{ color:#1e293b; }
.note-hero.has-cover .note-meta__date{ color:rgba(255,255,255,.8); }
.note-meta__chip{ background:rgba(255,255,255,.75); padding:.25rem .6rem; border-radius:999px; font-size:.85rem; }
.note-hero__actions{ display:flex; gap:.75rem; flex-wrap:wrap; }
.note-tags{ margin-top:.75rem; display:flex; gap:.5rem; flex-wrap:wrap; }
.note-tag{ display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:999px; background:rgba(148,163,184,.15); color:#0f172a; font-size:.85rem; position:relative; }
.note-tag::before{ content:''; width:8px; height:8px; border-radius:50%; background:var(--tag-color,#6366f1); }

.note-layout{ display:grid; gap:1.5rem; }
@media (min-width: 1100px){ .note-layout{ grid-template-columns: minmax(0,1fr) 320px; align-items:start; } }

.note-content{ padding:2rem; display:grid; gap:1.5rem; }
.note-blocks{ display:grid; gap:1.5rem; }
.note-block p{ margin:0; font-size:1.05rem; line-height:1.7; color:#0f172a; }
.note-block__heading{ margin:0; font-weight:700; color:#0f172a; }
.note-block__heading--h1{ font-size:2rem; }
.note-block__heading--h2{ font-size:1.6rem; }
.note-block__heading--h3{ font-size:1.3rem; }
.note-block__todo{ display:flex; gap:.75rem; align-items:flex-start; font-size:1.05rem; line-height:1.6; }
.note-block__todo input{ margin-top:.3rem; width:1.1rem; height:1.1rem; }
.note-block__list{ margin:0; padding-left:1.5rem; display:grid; gap:.5rem; font-size:1.05rem; line-height:1.6; color:#0f172a; }
.note-block__quote{ margin:0; padding:1rem 1.5rem; border-left:4px solid #6366f1; background:rgba(99,102,241,.08); border-radius:0 1rem 1rem 0; color:#312e81; font-size:1.05rem; }
.note-block__callout{ display:flex; gap:1rem; padding:1.25rem; background:rgba(148,163,184,.16); border-radius:1rem; border:1px solid rgba(148,163,184,.35); border-left:6px solid var(--callout-accent,#6366f1); }
.note-block__callout-icon{ font-size:1.5rem; }
.note-block__divider{ height:2px; background:linear-gradient(90deg,rgba(148,163,184,.4),rgba(203,213,225,.1)); border-radius:999px; }

.note-sidebar{ display:grid; gap:1rem; }
.note-panel{ padding:1.5rem; display:grid; gap:1rem; }
.note-panel__header{ display:flex; justify-content:space-between; align-items:center; gap:.75rem; }
.note-panel__header h2{ margin:0; font-size:1.1rem; }
.note-panel__body{ display:grid; gap:.75rem; }
.note-properties{ margin:0; padding:0; display:grid; gap:.85rem; }
.note-properties__item{ display:grid; gap:.35rem; }
.note-properties__item dt{ font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.note-properties__item dd{ margin:0; font-size:1rem; color:#0f172a; }
.note-detail-row{ display:flex; justify-content:space-between; gap:1rem; font-size:.95rem; }
.note-detail-label{ font-weight:600; color:#475569; }
.note-detail-value{ display:flex; gap:.35rem; flex-wrap:wrap; }
.note-photos{ display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); }
.note-photo-thumb{ display:block; border-radius:12px; overflow:hidden; box-shadow:0 10px 24px rgba(15,23,42,.15); }
.note-photo-thumb img{ width:100%; height:100%; object-fit:cover; aspect-ratio:4/3; }
.note-photo-empty{ border:2px dashed rgba(148,163,184,.4); border-radius:12px; display:grid; place-items:center; min-height:110px; font-size:.85rem; }

.note-discussion{ padding:2rem; display:grid; gap:1.5rem; }
.note-discussion__body{ display:grid; gap:1.5rem; }
.note-comments{ display:grid; gap:1rem; }
.note-comment{ border:1px solid rgba(148,163,184,.35); border-radius:1rem; padding:1rem 1.25rem; background:#fff; display:grid; gap:.75rem; }
.note-comment__header{ display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; }
.note-comment__timestamp{ display:block; font-size:.8rem; color:#64748b; margin-top:.15rem; }
.note-comment__delete .btn{ font-size:.75rem; }
.note-comment__body{ white-space:pre-wrap; color:#0f172a; line-height:1.55; }
.note-comment__footer details{ font-size:.9rem; }
.note-comment__children{ border-left:3px solid rgba(148,163,184,.4); margin-left:.75rem; padding-left:.75rem; display:grid; gap:.75rem; }
.note-comment-form{ display:grid; gap:.75rem; }
.note-comment-form textarea{ width:100%; border-radius:.75rem; border:1px solid #cbd5f5; padding:.65rem .8rem; resize:vertical; }
.note-comment-form--new label{ display:grid; gap:.4rem; }

.badge{ display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .7rem; border-radius:999px; font-size:.75rem; font-weight:600; }
.badge--muted{ background:rgba(148,163,184,.2); color:#475569; }
.badge--blue{ background:#dbeafe; color:#1d4ed8; }
.badge--indigo{ background:#e0e7ff; color:#4338ca; }
.badge--purple{ background:#ede9fe; color:#6b21a8; }
.badge--orange{ background:#ffedd5; color:#c2410c; }
.badge--green{ background:#dcfce7; color:#15803d; }
.badge--slate{ background:#e2e8f0; color:#334155; }
.badge--danger{ background:#fee2e2; color:#b91c1c; }
.badge--amber{ background:#fef3c7; color:#b45309; }
.badge--teal{ background:#ccfbf1; color:#0f766e; }
.muted{ color:#64748b; }
.is-overdue{ color:#b91c1c; font-weight:600; }

.photo-modal .photo-modal-box{ max-width:1080px; width:92vw; height:86vh; }
.photo-modal .photo-modal-body{ height:calc(86vh - 56px); }

@media (max-width:720px){
  .note-hero__inner{ padding:2rem; }
  .note-icon{ width:56px; height:56px; font-size:1.6rem; }
  .note-layout{ grid-template-columns:1fr; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const page   = document.querySelector('[data-note-page]');
  const grid   = document.getElementById('noteViewPhotoGrid');
  const modal  = document.getElementById('noteViewPhotoModal');
  const bodyEl = document.getElementById('noteViewPhotoModalBody');
  const openAllBtn = document.getElementById('openAllNotePhotos');
  const csrfField = '<?= CSRF_TOKEN_NAME; ?>';

  if (page) {
    const csrf = page.getAttribute('data-csrf') || '';
    const noteId = page.getAttribute('data-note-id') || '';
    page.querySelectorAll('[data-block-toggle]').forEach((input) => {
      if (input.disabled) { return; }
      input.addEventListener('change', () => {
        const uid = input.getAttribute('data-block-toggle');
        if (!uid) { return; }
        if (!csrf) { input.checked = !input.checked; return; }
        const formData = new FormData();
        formData.append('toggle_block', uid);
        formData.append('checked', input.checked ? '1' : '0');
        formData.append(csrfField, csrf);
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

  function openModal(){ modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function closeModal(){ modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; bodyEl.innerHTML=''; }

  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target.matches('[data-close-note-view], .photo-modal-backdrop')) {
        closeModal();
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });
  }

  function injectImages(urls){
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
