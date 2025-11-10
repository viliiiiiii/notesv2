<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$me   = current_user();
$meId = (int)($me['id'] ?? 0);

$id   = (int)($_GET['id'] ?? 0);
$note = notes_fetch($id);
if (!$note || !notes_can_view($note)) {
    redirect_with_message('index.php', 'Note not found or no access.', 'error');
    exit;
}

$noteDateValue = trim((string)($note['note_date'] ?? ''));
if ($noteDateValue === '' || $noteDateValue === '0000-00-00') {
    $note['note_date'] = date('Y-m-d');
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
        if (isset($_POST['save_template']) && $canEdit) {
            $templateName = trim((string)($_POST['template_name'] ?? ''));
            if ($templateName === '') {
                $errors[] = 'Template name is required.';
            } else {
                try {
                    notes_create_template_from_note($id, $meId, $templateName);
                    redirect_with_message('edit.php?id=' . $id, 'Template saved for quick reuse.', 'success');
                } catch (Throwable $e) {
                    error_log('notes_create_template_from_note failed: ' . $e->getMessage());
                    $errors[] = 'Unable to save template.';
                }
            }
        }

        if (isset($_POST['delete_note']) && $canEdit) {
            notes_delete($id);
            log_event('note.delete', 'note', $id);
            redirect_with_message('index.php', 'Note deleted.', 'success');
        }

        if (isset($_POST['save_note']) && $canEdit) {
            $noteDate = (string)($_POST['note_date'] ?? ($note['note_date'] ?? date('Y-m-d')));
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
            $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));

            try {
                $result = notes_apply_shares($id, $selected, $note, true);
                $currentShares = $result['after'];
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
      <form method="post" class="card card--surface note-template" novalidate>
        <h2>Template</h2>
        <p class="muted">Capture this page structure for new notes.</p>
        <label class="note-template__field">Template name
          <input type="text" name="template_name" maxlength="200" value="<?= sanitize($_POST['template_name'] ?? ($note['title'] ?? '')); ?>" required>
        </label>
        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
        <div class="note-template__actions">
          <button class="btn secondary" type="submit" name="save_template" value="1">Save as template</button>
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
.note-page{ display:grid; gap:1.25rem; padding-bottom:2rem; }
.note-page__header{ display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; flex-wrap:wrap; }
.note-page__header h1{ margin:0; font-weight:600; }
.note-page__subtitle{ margin:.35rem 0 0; color:#64748b; max-width:42ch; }
.note-layout{ display:grid; gap:1.25rem; }
@media (min-width:1024px){ .note-layout{ grid-template-columns:minmax(0,2fr) minmax(0,1fr); align-items:start; } }

.note-form{ display:grid; gap:1.25rem; }
.note-composer{ background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; box-shadow:0 6px 18px rgba(15,23,42,.04); }
.note-sidebar{ display:grid; gap:1rem; }

.note-template{ display:grid; gap:.7rem; }
.note-template__field{ display:grid; gap:.35rem; font-size:.85rem; color:#475569; }
.note-template__field input{ border-radius:.55rem; border:1px solid #d0d7e2; padding:.55rem .7rem; }
.note-template__actions{ display:flex; justify-content:flex-end; }

.note-cover{ position:relative; height:170px; background:linear-gradient(135deg,#f8fafc,#e2e8f0); border-radius:16px 16px 0 0; background-size:cover; background-position:center; }
.note-cover__overlay{ position:absolute; inset:0; padding:1rem 1.25rem; display:flex; justify-content:space-between; align-items:flex-end; gap:.75rem; background:linear-gradient(180deg,rgba(15,23,42,0.05),rgba(15,23,42,0.25)); color:#f8fafc; }
.note-cover__control{ display:flex; flex-direction:column; gap:.35rem; font-size:.85rem; }
.note-cover__control input{ border-radius:10px; border:1px solid rgba(255,255,255,.6); background:rgba(15,23,42,.25); color:#fff; padding:.4rem .7rem; }
.note-cover__control input::placeholder{ color:rgba(248,250,252,.8); }
.note-cover__overlay .btn{ font-size:.85rem; }

.note-head{ display:flex; gap:1rem; align-items:center; padding:1.25rem 1.5rem 0; }
.note-head__icon{ width:52px; height:52px; border-radius:14px; background:#f1f5f9; border:1px solid #e2e8f0; display:grid; place-items:center; font-size:1.6rem; }
.note-head__fields{ display:flex; flex-direction:column; gap:.45rem; width:100%; }
.note-head__icon-input label{ font-size:.8rem; color:#64748b; }
.note-head__icon-input input{ width:72px; padding:.45rem .55rem; border-radius:.65rem; border:1px solid #d0d7e2; background:#fff; }
.note-head__title{ font-size:1.8rem; font-weight:600; border:none; border-bottom:1px solid transparent; padding:.2rem 0; width:100%; }
.note-head__title:focus{ outline:none; border-color:#6366f1; }

.note-meta{ padding:0 1.5rem 1.25rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.9rem; }
.note-meta label{ display:flex; flex-direction:column; gap:.35rem; font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.note-meta input, .note-meta select{ padding:.55rem .75rem; border-radius:.65rem; border:1px solid #d0d7e2; background:#fff; font-size:.95rem; }

.note-tags{ padding:1.25rem 1.5rem; border-top:1px solid #e2e8f0; display:grid; gap:.6rem; }
.note-tags__header{ display:flex; gap:.65rem; align-items:center; font-weight:600; }
.note-tags__suggestions{ display:flex; gap:.35rem; font-size:.75rem; color:#64748b; flex-wrap:wrap; }
.note-tags__list{ display:flex; flex-wrap:wrap; gap:.4rem; }
.note-tags__input{ border:1px dashed #d0d7e2; border-radius:.65rem; padding:.45rem .7rem; min-width:180px; background:#f8fafc; }
.note-tag{ display:inline-flex; align-items:center; gap:.3rem; background:#f1f5f9; border:1px solid #e2e8f0; color:#1f2937; padding:.3rem .6rem; border-radius:999px; font-size:.8rem; position:relative; }
.note-tag::before{ content:''; width:6px; height:6px; border-radius:50%; background:var(--tag-color,#6366f1); }
.note-tag__remove{ background:none; border:none; cursor:pointer; color:inherit; font-size:.9rem; line-height:1; padding:0; }

.note-properties{ padding:0 1.5rem 1.5rem; display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:.9rem; border-top:1px solid #e2e8f0; }
.note-properties__item label{ display:flex; flex-direction:column; gap:.35rem; font-size:.8rem; color:#475569; }
.note-properties__item input, .note-properties__item select{ padding:.55rem .7rem; border-radius:.65rem; border:1px solid #d0d7e2; background:#fff; }

.block-editor{ padding:1.5rem; border-top:1px solid #e2e8f0; display:grid; gap:1rem; }
.block-editor__header{ display:flex; justify-content:space-between; gap:.75rem; align-items:center; flex-wrap:wrap; }
.block-editor__header h2{ margin:0; font-size:1.05rem; font-weight:600; }
.block-editor__toolbar{ display:flex; gap:.45rem; flex-wrap:wrap; }
.block-editor__list{ display:grid; gap:.85rem; }
.composer-block{ border:1px solid #e2e8f0; border-radius:12px; padding:1rem; display:grid; gap:.7rem; background:#fff; box-shadow:0 4px 12px rgba(15,23,42,.03); }
.composer-block__head{ display:flex; justify-content:space-between; align-items:center; gap:.6rem; }
.composer-block__type{ padding:.35rem .6rem; border-radius:.6rem; border:1px solid #d0d7e2; background:#f8fafc; font-size:.8rem; }
.composer-block__actions{ display:flex; gap:.35rem; }
.composer-block__btn{ border:1px solid #d0d7e2; background:#f8fafc; border-radius:.5rem; padding:.3rem .55rem; cursor:pointer; font-size:.8rem; }
.composer-block__btn--danger{ color:#b91c1c; border-color:#fecaca; background:#fee2e2; }
.composer-block__btn:disabled{ opacity:.45; cursor:not-allowed; }
.composer-block__body{ display:grid; gap:.6rem; }
.composer-block__text{ min-height:110px; border-radius:.65rem; border:1px solid #d0d7e2; padding:.6rem .7rem; resize:vertical; font-size:.95rem; background:#fff; }
.composer-block__checkbox{ font-size:.82rem; color:#475569; display:flex; gap:.35rem; align-items:center; }
.composer-block__icon-input{ font-size:.82rem; display:flex; flex-direction:column; gap:.3rem; }
.composer-block__icon-input input{ width:80px; padding:.4rem .55rem; border-radius:.6rem; border:1px solid #d0d7e2; }
.composer-block__hint{ margin:0; font-size:.78rem; color:#94a3b8; }
.composer-block__divider{ height:1px; background:#e2e8f0; border-radius:999px; }
.note-form__actions{ display:flex; justify-content:flex-end; gap:.8rem; flex-wrap:wrap; padding:0 1.5rem 1.5rem; }
.note-attachments__slots{ display:grid; gap:.75rem; }
.note-attachment{ border:1px solid #e2e8f0; border-radius:12px; background:#fff; overflow:hidden; display:grid; gap:.65rem; box-shadow:0 4px 12px rgba(15,23,42,.04); }
.note-attachment__preview{ background:#f8fafc; display:grid; place-items:center; min-height:130px; }
.note-attachment__preview img{ width:100%; height:100%; object-fit:cover; }
.note-attachment__actions{ display:flex; gap:.5rem; justify-content:space-between; padding:0 1rem 1rem; flex-wrap:wrap; }

.note-upload{ display:flex; gap:.45rem; align-items:center; }
.note-share__list{ display:grid; gap:.45rem; margin-top:.75rem; }
.note-share__option{ display:flex; align-items:center; gap:.6rem; padding:.6rem .75rem; border:1px solid #e2e8f0; border-radius:.65rem; background:#fff; }
.note-share__actions{ margin-top:.9rem; display:flex; justify-content:flex-end; }
.note-danger{ border:1px solid #fecaca; background:#fff7f7; padding:1.1rem 1.25rem; border-radius:12px; display:grid; gap:.65rem; }

.card--surface{ background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 4px 14px rgba(15,23,42,.04); padding:1.25rem 1.5rem; }
.badge{ padding:.25rem .55rem; border-radius:999px; font-size:.72rem; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; }
.muted{ color:#64748b; font-size:.9rem; }
.btn.danger{ background:#ef4444; border-color:#ef4444; color:#fff; }
.btn.danger:hover{ background:#dc2626; border-color:#dc2626; }
.btn.secondary{ background:#f8fafc; border:1px solid #d0d7e2; color:#1e293b; }
.btn.secondary:hover{ background:#e2e8f0; }
.visually-hidden{ position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }

@media (max-width:720px){
  .note-head{ flex-direction:column; align-items:flex-start; }
  .note-head__fields{ width:100%; }
  .note-page__header{ flex-direction:column; align-items:flex-start; }
  .card--surface{ padding:1.1rem 1.25rem; }
}
</style>


<script src="composer.js"></script>

<?php include __DIR__ . '/../includes/footer.php';
