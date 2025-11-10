<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$canEdit      = notes_can_edit($note);
$canShare     = notes_can_share($note);
$photos       = notes_fetch_photos($id);
$shareOptions = notes_all_users();
$currentShares= notes_get_share_user_ids($id);
if (!is_array($currentShares)) { $currentShares = []; }
$ownerId      = (int)$note['user_id'];

$meta            = notes_fetch_page_meta($id);
$tags            = notes_fetch_note_tags($id);
$blocks          = notes_fetch_blocks($id);
$tagOptions      = notes_all_tag_options();
$statuses        = notes_available_statuses();
$priorityOptions = notes_priority_options();

$errors = [];

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (isset($_POST['delete_note']) && $canEdit) {
            notes_delete($id);
            log_event('note.delete', 'note', $id);
            redirect_with_message('index.php', 'Note deleted.', 'success');
        }

        if (isset($_POST['save_note']) && $canEdit) {
            $noteDate = (string)($_POST['note_date'] ?? '');
            $title    = trim((string)($_POST['title'] ?? ''));
            $bodyRaw  = trim((string)($_POST['body'] ?? ''));
            $icon     = trim((string)($_POST['icon'] ?? ''));
            $coverUrl = trim((string)($_POST['cover_url'] ?? ''));
            $status   = notes_normalize_status($_POST['status'] ?? ($meta['status'] ?? NOTES_DEFAULT_STATUS));

            $props = notes_default_properties();
            $props['project']  = trim((string)($_POST['property_project'] ?? ''));
            $props['location'] = trim((string)($_POST['property_location'] ?? ''));
            $props['due_date'] = trim((string)($_POST['property_due_date'] ?? ''));
            if ($props['due_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $props['due_date'])) {
                $errors[] = 'Due date must be YYYY-MM-DD.';
                $props['due_date'] = '';
            }
            $priorityInput = trim((string)($_POST['property_priority'] ?? ''));
            $props['priority'] = in_array($priorityInput, $priorityOptions, true)
                ? $priorityInput
                : ($meta['properties']['priority'] ?? 'Medium');

            $tagsPayload = json_decode((string)($_POST['tags_payload'] ?? '[]'), true);
            $tags        = notes_normalize_tags_input(is_array($tagsPayload) ? $tagsPayload : []);

            [$blocks, $bodyPlain] = notes_parse_blocks_payload($_POST['blocks_payload'] ?? '', $bodyRaw);

            $data = [
                'note_date'  => $noteDate,
                'title'      => $title,
                'body'       => $bodyPlain,
                'icon'       => $icon,
                'cover_url'  => $coverUrl,
                'status'     => $status,
                'properties' => $props,
                'tags'       => $tags,
                'blocks'     => $blocks,
            ];

            if ($data['title'] === '') {
                $errors[] = 'Title is required.';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['note_date'])) {
                $errors[] = 'Valid date is required.';
            }

            if (!$errors) {
                notes_update($id, $data);
                log_event('note.update', 'note', $id);
                redirect_with_message('view.php?id=' . $id, 'Note updated.', 'success');
            }

            $note = array_merge($note, ['note_date' => $noteDate, 'title' => $title, 'body' => $bodyPlain]);
            $meta = [
                'icon'       => $icon,
                'cover_url'  => $coverUrl,
                'status'     => $status,
                'properties' => $props,
            ];
        }

        if (isset($_POST['save_shares']) && $canShare) {
            $before   = array_map('intval', notes_get_share_user_ids($id) ?: []);
            $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
            $selected = array_values(array_filter($selected, fn($u) => $u !== $ownerId));

            try {
                notes_update_shares($id, $selected);
                $after = array_map('intval', notes_get_share_user_ids($id) ?: []);
                $added = array_values(array_diff($after, $before));

                if ($added) {
                    try {
                        $me   = current_user();
                        $who  = $me['email'] ?? 'Someone';
                        $t    = trim((string)($note['title'] ?? 'Untitled'));
                        $date = (string)($note['note_date'] ?? '');
                        $titleMsg   = "A note was shared with you";
                        $bodyMsg    = "“{$t}” {$date} — shared by {$who}";
                        $link       = "/notes/view.php?id=" . (int)$id;
                        $payload    = ['note_id' => (int)$id, 'by' => $who];

                        if (function_exists('notify_users')) {
                            notify_users($added, 'note.shared', $titleMsg, $bodyMsg, $link, $payload);
                        } else {
                            error_log('notify_users() missing');
                        }
                        log_event('note.share', 'note', (int)$id, ['added' => $added]);
                    } catch (Throwable $nx) {
                        error_log('notify_users failed: ' . $nx->getMessage());
                    }
                }

                $currentShares = $after;
                redirect_with_message('edit.php?id=' . $id, 'Shares updated.', 'success');
            } catch (Throwable $e) {
                error_log('notes_update_shares failed: ' . $e->getMessage());
                $errors[] = 'Failed to update shares.';
            }
        }

        if (isset($_POST['upload_position']) && $canEdit) {
            $pos = (int)$_POST['upload_position'];
            if (in_array($pos, [1, 2, 3], true)) {
                try {
                    notes_save_uploaded_photo($id, $pos, 'photo');
                    redirect_with_message('edit.php?id=' . $id, "Photo $pos uploaded.", 'success');
                } catch (Throwable $e) {
                    $errors[] = 'Photo upload failed: ' . $e->getMessage();
                }
                $photos = notes_fetch_photos($id);
            } else {
                $errors[] = 'Bad photo position.';
            }
        }

        if (isset($_POST['delete_photo_id']) && $canEdit) {
            try {
                notes_remove_photo_by_id((int)$_POST['delete_photo_id']);
                redirect_with_message('edit.php?id=' . $id, 'Photo removed.', 'success');
            } catch (Throwable $e) {
                $errors[] = 'Failed to remove photo.';
            }
            $photos = notes_fetch_photos($id);
        }
    }
}

$title = 'Edit Note';
include __DIR__ . '/../includes/header.php';

$composerConfig = [
    'blocks'   => $blocks,
    'tags'     => $tags,
    'icon'     => $meta['icon'],
    'coverUrl' => $meta['cover_url'],
];
$configAttr    = htmlspecialchars(json_encode($composerConfig, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$selectedShares= array_flip($currentShares);
?>
<section class="note-page note-page--edit">
  <header class="note-page__header">
    <div>
      <h1>Edit note</h1>
      <p class="note-page__subtitle">Update content, status, and collaborators in a Notion-inspired workspace.</p>
    </div>
    <a class="btn" href="view.php?id=<?= $id; ?>">View note</a>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <div class="note-layout">
    <form method="post" class="note-form" novalidate>
      <div class="note-composer card card--surface" data-note-composer data-config="<?= $configAttr; ?>">
        <input type="hidden" name="blocks_payload" data-blocks-field>
        <input type="hidden" name="tags_payload" data-tags-field>
        <textarea name="body" data-body-fallback class="visually-hidden"><?= sanitize($note['body'] ?? ''); ?></textarea>

        <div class="note-cover" data-cover-preview>
          <div class="note-cover__overlay">
            <label class="note-cover__control">Cover image URL
              <input type="url" name="cover_url" data-cover-input placeholder="https://…" value="<?= sanitize($meta['cover_url']); ?>">
            </label>
            <button type="button" class="btn small secondary" data-cover-clear>Remove cover</button>
          </div>
        </div>

        <div class="note-head">
          <span class="note-head__icon" data-icon-preview><?= sanitize($meta['icon'] ?: '📄'); ?></span>
          <div class="note-head__fields">
            <label class="note-head__icon-input">Icon
              <input type="text" name="icon" maxlength="4" data-icon-input placeholder="💡" value="<?= sanitize($meta['icon']); ?>">
            </label>
            <input class="note-head__title" type="text" name="title" value="<?= sanitize($note['title'] ?? ''); ?>" placeholder="Untitled" required>
          </div>
        </div>

        <div class="note-meta">
          <label>Date
            <input type="date" name="note_date" value="<?= sanitize($note['note_date'] ?? ''); ?>" required>
          </label>
          <label>Status
            <select name="status">
              <?php foreach ($statuses as $slug => $label): ?>
                <option value="<?= sanitize($slug); ?>" <?= notes_normalize_status($_POST['status'] ?? ($meta['status'] ?? NOTES_DEFAULT_STATUS)) === $slug ? 'selected' : ''; ?>><?= sanitize($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="note-tags" data-tag-section>
          <div class="note-tags__header">
            <span>Tags</span>
            <?php if ($tagOptions): ?>
              <div class="note-tags__suggestions" aria-hidden="true">
                <?php foreach (array_slice($tagOptions, 0, 3) as $tag): ?>
                  <span class="note-tag-chip" style="--tag-chip: <?= sanitize($tag['color']); ?>;">#<?= sanitize($tag['label']); ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="note-tags__list" data-tag-list></div>
          <input type="text" class="note-tags__input" data-tag-input placeholder="Add tag and press Enter">
        </div>

        <div class="note-properties">
          <div class="note-properties__item">
            <label>Project
              <input type="text" name="property_project" value="<?= sanitize($meta['properties']['project'] ?? ''); ?>" placeholder="Site or initiative">
            </label>
          </div>
          <div class="note-properties__item">
            <label>Location
              <input type="text" name="property_location" value="<?= sanitize($meta['properties']['location'] ?? ''); ?>" placeholder="Area, floor, building">
            </label>
          </div>
          <div class="note-properties__item">
            <label>Due date
              <input type="date" name="property_due_date" value="<?= sanitize($meta['properties']['due_date'] ?? ''); ?>">
            </label>
          </div>
          <div class="note-properties__item">
            <label>Priority
              <select name="property_priority">
                <?php foreach ($priorityOptions as $option): ?>
                  <option value="<?= sanitize($option); ?>" <?= (($meta['properties']['priority'] ?? 'Medium') === $option) ? 'selected' : ''; ?>><?= sanitize($option); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>

        <section class="block-editor">
          <header class="block-editor__header">
            <h2>Page content</h2>
            <div class="block-editor__toolbar" data-block-toolbar>
              <button type="button" class="chip" data-add-block="paragraph">Text</button>
              <button type="button" class="chip" data-add-block="heading1">Heading 1</button>
              <button type="button" class="chip" data-add-block="heading2">Heading 2</button>
              <button type="button" class="chip" data-add-block="todo">To-do</button>
              <button type="button" class="chip" data-add-block="bulleted">Bulleted list</button>
              <button type="button" class="chip" data-add-block="numbered">Numbered list</button>
              <button type="button" class="chip" data-add-block="callout">Callout</button>
              <button type="button" class="chip" data-add-block="quote">Quote</button>
              <button type="button" class="chip" data-add-block="divider">Divider</button>
            </div>
          </header>
          <div class="block-editor__list" data-block-list></div>
        </section>
      </div>

      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
      <div class="note-form__actions">
        <button class="btn primary" type="submit" name="save_note" value="1">Save changes</button>
        <a class="btn secondary" href="view.php?id=<?= $id; ?>">Cancel</a>
      </div>
    </form>

    <aside class="note-sidebar">
      <div class="card card--surface note-attachments">
        <h2>Attachments</h2>
        <p class="muted">Manage up to three photos for this note.</p>
        <div class="note-attachments__slots">
          <?php for ($i = 1; $i <= 3; $i++): $photo = $photos[$i] ?? null; ?>
            <div class="note-attachment">
              <div class="note-attachment__preview">
                <?php if ($photo): ?>
                  <img src="<?= sanitize($photo['url']); ?>" alt="Attachment <?= $i; ?>">
                <?php else: ?>
                  <span class="muted">Empty slot <?= $i; ?></span>
                <?php endif; ?>
              </div>
              <div class="note-attachment__actions">
                <?php if ($photo): ?>
                  <a class="btn small" href="<?= sanitize($photo['url']); ?>" target="_blank" rel="noopener">Open</a>
                  <?php if ($canEdit): ?>
                  <form method="post" onsubmit="return confirm('Remove this attachment?');">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                    <button class="btn small secondary" type="submit" name="delete_photo_id" value="<?= (int)$photo['id']; ?>">Remove</button>
                  </form>
                  <?php endif; ?>
                <?php elseif ($canEdit): ?>
                  <form method="post" enctype="multipart/form-data" class="note-upload">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
                    <input type="hidden" name="upload_position" value="<?= $i; ?>">
                    <label class="btn small">
                      Choose
                      <input type="file" name="photo" accept="image/*,image/heic,image/heif" class="visually-hidden" required>
                    </label>
                    <button class="btn primary small" type="submit">Upload</button>
                  </form>
                <?php else: ?>
                  <span class="muted">No attachment.</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <?php if ($canShare): ?>
      <form method="post" class="card card--surface note-share">
        <h2>Share with teammates</h2>
        <p class="muted">Select collaborators who should have access.</p>
        <div class="note-share__list">
          <?php foreach ($shareOptions as $user): $uid = (int)($user['id'] ?? 0); $label = trim((string)($user['email'] ?? '')); ?>
            <label class="note-share__option">
              <input type="checkbox" name="shared_ids[]" value="<?= $uid; ?>" <?= isset($selectedShares[$uid]) ? 'checked' : ''; ?> <?= $uid === $ownerId ? 'disabled' : ''; ?>>
              <span><?= sanitize($label !== '' ? $label : ('User #'.$uid)); ?></span>
              <?php if ($uid === $ownerId): ?><span class="badge">Owner</span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
        <div class="note-share__actions">
          <button class="btn primary" type="submit" name="save_shares" value="1">Update sharing</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if ($canEdit): ?>
      <form method="post" class="card note-danger" onsubmit="return confirm('Delete this note? This cannot be undone.');">
        <h2>Danger zone</h2>
        <p class="muted">Deleting removes the note, its blocks, comments, and attachments.</p>
        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
        <button class="btn danger" type="submit" name="delete_note" value="1">Delete note</button>
      </form>
      <?php endif; ?>
    </aside>
  </div>
</section>

<style>
.note-page{ display:grid; gap:1.5rem; }
.note-page__header{ display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
.note-page__header h1{ margin:0; }
.note-page__subtitle{ margin:.35rem 0 0; color:#64748b; max-width:42ch; }
.note-layout{ display:grid; gap:1.5rem; }
@media (min-width:1024px){ .note-layout{ grid-template-columns:minmax(0,2fr) minmax(0,1fr); align-items:start; } }

.note-form{ display:grid; gap:1.5rem; }
.note-composer{ padding:0; overflow:hidden; }
.note-sidebar{ display:grid; gap:1.5rem; }

.note-cover{ position:relative; height:220px; background:linear-gradient(135deg,#e0f2fe,#c7d2fe); border-radius:18px 18px 0 0; background-size:cover; background-position:center; }
.note-cover__overlay{ position:absolute; inset:0; padding:1rem; display:flex; justify-content:space-between; align-items:flex-end; background:linear-gradient(180deg,rgba(15,23,42,0.05),rgba(15,23,42,0.35)); gap:.75rem; }
.note-cover__control{ display:flex; flex-direction:column; gap:.35rem; color:#f8fafc; font-size:.9rem; }
.note-cover__control input{ border-radius:10px; border:1px solid rgba(255,255,255,.6); background:rgba(15,23,42,.35); color:#fff; padding:.45rem .75rem; }

.note-head{ display:flex; gap:1rem; align-items:center; padding:1.25rem 1.5rem 0; }
.note-head__icon{ font-size:3rem; }
.note-head__fields{ display:flex; flex-direction:column; gap:.5rem; width:100%; }
.note-head__icon-input input{ width:70px; padding:.45rem .6rem; border-radius:.75rem; border:1px solid #cbd5f5; }
.note-head__title{ font-size:2rem; font-weight:600; border:none; border-bottom:1px solid transparent; padding:.25rem 0; width:100%; }
.note-head__title:focus{ outline:none; border-color:#6366f1; }

.note-meta{ padding:0 1.5rem 1.25rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; }
.note-meta label{ display:flex; flex-direction:column; gap:.35rem; font-size:.85rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.note-meta input, .note-meta select{ padding:.6rem .75rem; border-radius:.75rem; border:1px solid #cbd5f5; }

.note-tags{ padding:1.25rem 1.5rem; border-top:1px solid #e2e8f0; display:grid; gap:.75rem; }
.note-tags__header{ display:flex; gap:.75rem; align-items:center; font-weight:600; }
.note-tags__suggestions{ display:flex; gap:.35rem; font-size:.75rem; color:#64748b; }
.note-tags__list{ display:flex; flex-wrap:wrap; gap:.5rem; }
.note-tags__input{ border:1px dashed #cbd5f5; border-radius:.75rem; padding:.5rem .75rem; min-width:180px; }
.note-tag{ display:inline-flex; align-items:center; gap:.35rem; background:rgba(99,102,241,.1); color:#312e81; padding:.35rem .7rem; border-radius:999px; font-size:.85rem; position:relative; }
.note-tag::before{ content:''; width:8px; height:8px; border-radius:50%; background:var(--tag-color,#6366f1); }
.note-tag__remove{ background:none; border:none; cursor:pointer; color:inherit; font-size:1rem; line-height:1; padding:0; }
.note-tag-chip{ background:var(--tag-chip,#94a3b8); color:#fff; padding:.25rem .55rem; border-radius:999px; font-size:.75rem; }

.note-properties{ padding:0 1.5rem 1.5rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; border-top:1px solid #e2e8f0; }
.note-properties__item label{ display:flex; flex-direction:column; gap:.35rem; font-size:.85rem; color:#475569; }
.note-properties__item input, .note-properties__item select{ padding:.55rem .75rem; border-radius:.75rem; border:1px solid #cbd5f5; }

.block-editor{ padding:1.5rem; border-top:1px solid #e2e8f0; display:grid; gap:1rem; }
.block-editor__header{ display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
.block-editor__header h2{ margin:0; font-size:1.15rem; }
.block-editor__toolbar{ display:flex; gap:.5rem; flex-wrap:wrap; }
.block-editor__list{ display:grid; gap:1rem; }
.composer-block{ border:1px solid #e2e8f0; border-radius:12px; padding:1rem; display:grid; gap:.75rem; background:#fff; box-shadow:0 4px 16px rgba(15,23,42,.05); }
.composer-block__head{ display:flex; justify-content:space-between; align-items:center; gap:.75rem; }
.composer-block__type{ padding:.45rem .7rem; border-radius:.75rem; border:1px solid #cbd5f5; }
.composer-block__actions{ display:flex; gap:.35rem; }
.composer-block__btn{ border:1px solid #cbd5f5; background:#f8fafc; border-radius:.5rem; padding:.3rem .6rem; cursor:pointer; }
.composer-block__btn--danger{ color:#dc2626; border-color:#fecaca; background:#fee2e2; }
.composer-block__btn:disabled{ opacity:.4; cursor:not-allowed; }
.composer-block__body{ display:grid; gap:.65rem; }
.composer-block__text{ min-height:110px; border-radius:.75rem; border:1px solid #cbd5f5; padding:.65rem .75rem; resize:vertical; }
.composer-block__checkbox{ font-size:.85rem; color:#475569; display:flex; gap:.35rem; align-items:center; }
.composer-block__icon-input{ font-size:.85rem; display:flex; flex-direction:column; gap:.35rem; }
.composer-block__icon-input input{ width:80px; padding:.45rem .6rem; border-radius:.65rem; border:1px solid #cbd5f5; }
.composer-block__hint{ margin:0; font-size:.8rem; color:#94a3b8; }
.composer-block__divider{ height:3px; background:linear-gradient(90deg,#cbd5f5,#e2e8f0); border-radius:999px; }

.note-form__actions{ display:flex; justify-content:flex-end; gap:1rem; flex-wrap:wrap; }
.note-attachments__slots{ display:grid; gap:1rem; }
.note-attachment{ border:1px solid #e2e8f0; border-radius:12px; background:#fff; overflow:hidden; display:grid; gap:.75rem; }
.note-attachment__preview{ background:#f1f5f9; display:grid; place-items:center; min-height:140px; }
.note-attachment__preview img{ width:100%; height:100%; object-fit:cover; }
.note-attachment__actions{ display:flex; gap:.5rem; justify-content:space-between; padding:0 1rem 1rem; flex-wrap:wrap; }
.note-upload{ display:flex; gap:.5rem; align-items:center; }
.note-share__list{ display:grid; gap:.5rem; margin-top:1rem; }
.note-share__option{ display:flex; align-items:center; gap:.5rem; padding:.6rem .75rem; border:1px solid #e2e8f0; border-radius:.75rem; background:#fff; }
.note-share__actions{ margin-top:1rem; display:flex; justify-content:flex-end; }
.note-danger{ border:1px solid #fecdd3; background:#fff1f2; padding:1.25rem; border-radius:1rem; display:grid; gap:.75rem; }

.card--surface{ background:var(--card-surface,#ffffffeb); backdrop-filter:blur(12px); }
.badge{ padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; background:#f3f6fb; color:#334155; }
.muted{ color:#64748b; font-size:.9rem; }
.btn.danger{ background:#ef4444; border-color:#ef4444; color:#fff; }
.btn.danger:hover{ background:#dc2626; border-color:#dc2626; }
.visually-hidden{ position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }

@media (max-width:720px){
  .note-head{ flex-direction:column; align-items:flex-start; }
  .note-head__fields{ width:100%; }
  .note-page__header{ flex-direction:column; align-items:flex-start; }
}
</style>

<script src="composer.js"></script>

<?php include __DIR__ . '/../includes/footer.php';
