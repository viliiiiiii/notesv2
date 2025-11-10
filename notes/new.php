<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$errors          = [];
$today           = date('Y-m-d');
$statuses        = notes_available_statuses();
$priorityOptions = notes_priority_options();
$properties      = notes_default_properties();
$meta            = [
    'icon'       => '',
    'cover_url'  => '',
    'status'     => NOTES_DEFAULT_STATUS,
    'properties' => $properties,
];
$tags            = [];
$blocks          = [];
$tagOptions      = notes_all_tag_options();

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $noteDate = (string)($_POST['note_date'] ?? $today);
        $title    = trim((string)($_POST['title'] ?? ''));
        $bodyRaw  = trim((string)($_POST['body'] ?? ''));
        $icon     = trim((string)($_POST['icon'] ?? ''));
        $coverUrl = trim((string)($_POST['cover_url'] ?? ''));
        $status   = notes_normalize_status($_POST['status'] ?? NOTES_DEFAULT_STATUS);

        $properties = notes_default_properties();
        $properties['project']  = trim((string)($_POST['property_project'] ?? ''));
        $properties['location'] = trim((string)($_POST['property_location'] ?? ''));
        $properties['due_date'] = trim((string)($_POST['property_due_date'] ?? ''));
        if ($properties['due_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $properties['due_date'])) {
            $errors[] = 'Due date must be YYYY-MM-DD.';
            $properties['due_date'] = '';
        }
        $priorityInput = trim((string)($_POST['property_priority'] ?? ''));
        $properties['priority'] = in_array($priorityInput, $priorityOptions, true) ? $priorityInput : $properties['priority'];

        $tagsPayload = json_decode((string)($_POST['tags_payload'] ?? '[]'), true);
        $tags        = notes_normalize_tags_input(is_array($tagsPayload) ? $tagsPayload : []);

        [$blocks, $bodyPlain] = notes_parse_blocks_payload($_POST['blocks_payload'] ?? '', $bodyRaw);

        $data = [
            'user_id'    => (int)(current_user()['id'] ?? 0),
            'note_date'  => $noteDate,
            'title'      => $title,
            'body'       => $bodyPlain,
            'icon'       => $icon,
            'cover_url'  => $coverUrl,
            'status'     => $status,
            'properties' => $properties,
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
            $id = notes_insert($data);
            for ($i = 1; $i <= 3; $i++) {
                if (!empty($_FILES["photo$i"]['name'])) {
                    try {
                        notes_save_uploaded_photo($id, $i, "photo$i");
                    } catch (Throwable $e) {
                        // swallow photo errors here; user can retry on edit screen
                    }
                }
            }
            redirect_with_message('view.php?id=' . $id, 'Note created.', 'success');
        }

        $meta = [
            'icon'       => $icon,
            'cover_url'  => $coverUrl,
            'status'     => $status,
            'properties' => $properties,
        ];
    }
}

$composerConfig = [
    'blocks'   => $blocks,
    'tags'     => $tags,
    'icon'     => $meta['icon'],
    'coverUrl' => $meta['cover_url'],
];
$composerJson = json_encode($composerConfig, JSON_UNESCAPED_UNICODE);

$title = 'New Note';
include __DIR__ . '/../includes/header.php';
$configAttr = htmlspecialchars($composerJson, ENT_QUOTES, 'UTF-8');
?>
<section class="note-page">
  <header class="note-page__header">
    <div>
      <h1>Create Notion-style Note</h1>
      <p class="note-page__subtitle">Rich blocks, properties, and share-ready attachments in one workspace page.</p>
    </div>
    <a class="btn" href="index.php">Back to Notes</a>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="note-form" novalidate>
    <div class="note-composer card card--surface" data-note-composer data-config="<?= $configAttr; ?>">
      <input type="hidden" name="blocks_payload" data-blocks-field>
      <input type="hidden" name="tags_payload" data-tags-field>
      <textarea name="body" data-body-fallback class="visually-hidden"><?= sanitize($_POST['body'] ?? ''); ?></textarea>

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
          <input class="note-head__title" type="text" name="title" value="<?= sanitize($_POST['title'] ?? ''); ?>" placeholder="Untitled" required>
        </div>
      </div>

      <div class="note-meta">
        <label>Date
          <input type="date" name="note_date" value="<?= sanitize($_POST['note_date'] ?? $today); ?>" required>
        </label>
        <label>Status
          <select name="status">
            <?php foreach ($statuses as $slug => $label): ?>
              <option value="<?= sanitize($slug); ?>" <?= notes_normalize_status($_POST['status'] ?? $meta['status']) === $slug ? 'selected' : ''; ?>><?= sanitize($label); ?></option>
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

    <div class="card card--surface note-attachments">
      <h2>Attachments</h2>
      <p class="muted">Upload reference photos or documents to accompany the note.</p>
      <div class="dropzone" id="dropZone" data-max-mb="<?= (int)NOTES_MAX_MB; ?>" aria-label="Drop images here">
        <div class="dz-icon" aria-hidden="true">📎</div>
        <div class="dz-text">
          <strong>Drag & drop photos</strong> here, or use the slots below.
          <div class="muted small">JPG/PNG/WebP/HEIC up to <?= (int)NOTES_MAX_MB; ?> MB each.</div>
        </div>
      </div>
      <div class="uploader-grid" id="uploaderGrid">
        <?php for ($i = 1; $i <= 3; $i++): ?>
          <div class="uploader-tile" data-slot="<?= $i; ?>">
            <div class="uploader-thumb" id="preview<?= $i; ?>">
              <span class="muted small">Photo <?= $i; ?></span>
            </div>
            <div class="uploader-actions">
              <label class="btn small">
                Choose
                <input id="photo<?= $i; ?>" type="file" name="photo<?= $i; ?>" accept="image/*,image/heic,image/heif" class="visually-hidden file-compact">
              </label>
              <button type="button" class="btn small secondary" data-clear="<?= $i; ?>">Clear</button>
            </div>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">

    <div class="note-form__actions">
      <button class="btn primary" type="submit">Create note</button>
      <a class="btn secondary" href="index.php">Cancel</a>
    </div>
  </form>
</section>

<style>
.note-page{ display:grid; gap:1.5rem; }
.note-page__header{ display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; }
.note-page__header h1{ margin:0; }
.note-page__subtitle{ margin:.35rem 0 0; color:#64748b; max-width:40ch; }

.note-form{ display:grid; gap:1.5rem; }
.note-composer{ padding:0; overflow:hidden; }

.note-cover{ position:relative; height:220px; background:linear-gradient(135deg,#e0f2fe,#c7d2fe); border-radius:18px 18px 0 0; background-size:cover; background-position:center; }
.note-cover.has-cover{ background-size:cover; }
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

.note-attachments{ display:grid; gap:1rem; }
.dropzone{ display:flex; align-items:center; gap:1rem; padding:1rem; border:2px dashed #cbd5f5; border-radius:16px; background:#f8fafc; }
.dropzone.is-drag{ background:#eef2ff; border-color:#6366f1; }
.dz-icon{ font-size:1.5rem; }
.dz-text{ line-height:1.35; }
.uploader-grid{ display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
.uploader-tile{ border:1px solid #e2e8f0; border-radius:12px; padding:.75rem; display:grid; gap:.6rem; background:#fff; }
.uploader-thumb{ border:1px dashed #e2e8f0; border-radius:10px; min-height:120px; display:grid; place-items:center; background:#f1f5f9; overflow:hidden; }
.uploader-thumb img{ width:100%; height:100%; object-fit:cover; }
.uploader-actions{ display:flex; justify-content:space-between; gap:.5rem; }

.note-form__actions{ display:flex; justify-content:flex-end; gap:1rem; flex-wrap:wrap; }

.card--surface{ background:var(--card-surface,#ffffffeb); backdrop-filter:blur(12px); }

.visually-hidden{ position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }

@media (max-width:720px){
  .note-head{ flex-direction:column; align-items:flex-start; }
  .note-head__fields{ width:100%; }
  .note-page__header{ flex-direction:column; align-items:flex-start; }
}
</style>

<script src="composer.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const maxMB = parseInt(document.getElementById('dropZone')?.dataset.maxMb || '70', 10);
  const maxBytes = maxMB * 1024 * 1024;
  const inputs = [1,2,3].map(i => document.getElementById('photo' + i));
  const previews = [1,2,3].map(i => document.getElementById('preview' + i));
  const dropZone = document.getElementById('dropZone');

  function clearSlot(i){
    const input = inputs[i-1], preview = previews[i-1];
    if (!input || !preview) return;
    try {
      const dt = new DataTransfer();
      input.files = dt.files;
    } catch(_) { input.value = ''; }
    preview.innerHTML = '<span class="muted small">Photo ' + i + '</span>';
  }

  function showPreview(i, file){
    const preview = previews[i-1];
    if (!preview) return;
    const reader = new FileReader();
    reader.onload = e => {
      preview.innerHTML = '';
      const img = document.createElement('img');
      img.src = e.target.result;
      img.alt = 'Preview ' + i;
      preview.appendChild(img);
    };
    reader.readAsDataURL(file);
  }

  function setFileToInput(input, file){
    if (!file) return false;
    if (file.size > maxBytes) {
      alert('File "'+ file.name +'" is too large. Max ' + maxMB + 'MB.');
      return false;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return true;
  }

  function firstEmptyInputIdx(){
    for (let i=0;i<inputs.length;i++){
      if (!inputs[i] || !inputs[i].files || inputs[i].files.length === 0) return i;
    }
    return -1;
  }

  inputs.forEach((input, idx) => {
    if (!input) return;
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) { clearSlot(idx+1); return; }
      if (file.size > maxBytes) {
        alert('File "'+ file.name +'" is too large. Max ' + maxMB + 'MB.');
        clearSlot(idx+1);
        return;
      }
      showPreview(idx+1, file);
    });
  });

  document.querySelectorAll('[data-clear]').forEach(btn => {
    btn.addEventListener('click', () => {
      const i = parseInt(btn.getAttribute('data-clear'), 10);
      clearSlot(i);
    });
  });

  if (dropZone){
    const on = () => dropZone.classList.add('is-drag');
    const off = () => dropZone.classList.remove('is-drag');

    ['dragenter','dragover'].forEach(ev =>
      dropZone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); on(); })
    );
    ['dragleave','dragend','drop'].forEach(ev =>
      dropZone.addEventListener(ev, e => { if (ev !== 'drop'){ off(); } })
    );

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault(); e.stopPropagation(); off();
      const files = Array.from(e.dataTransfer?.files || []).filter(f => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name));
      if (!files.length) return;
      let cursor = firstEmptyInputIdx();
      for (const file of files){
        if (cursor === -1) break;
        const input = inputs[cursor];
        if (input && setFileToInput(input, file)) {
          showPreview(cursor+1, file);
          cursor = firstEmptyInputIdx();
        }
      }
    });

    dropZone.addEventListener('click', () => {
      const idx = Math.max(0, firstEmptyInputIdx());
      if (inputs[idx]) {
        inputs[idx].click();
      }
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php';
