<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$me        = current_user();
$meId      = (int)($me['id'] ?? 0);
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
$templates       = notes_fetch_templates_for_user($meId);
$templateMap    = [];
foreach ($templates as $idx => $tpl) {
    $tid = (int)($tpl['id'] ?? 0);
    if ($tid <= 0) {
        continue;
    }
    $details = notes_template_share_details($tid);
    $shareIds = [];
    $shareLabels = [];
    foreach ($details as $share) {
        $shareIds[] = (int)($share['id'] ?? 0);
        $label = trim((string)($share['label'] ?? ''));
        if ($label !== '') {
            $shareLabels[] = $label;
        }
    }
    $templates[$idx]['share_ids'] = array_values(array_unique($shareIds));
    $templates[$idx]['share_labels'] = array_values($shareLabels);
    $templateMap[$tid] = $templates[$idx];
}
unset($tpl);

$selectedTemplateId = (int)($_POST['template_select'] ?? ($_GET['template'] ?? 0));
$prefillTemplate    = null;
if (!is_post() && $selectedTemplateId && isset($templateMap[$selectedTemplateId])) {
    $prefillTemplate = $templateMap[$selectedTemplateId];
    $meta['icon']       = trim((string)($prefillTemplate['icon'] ?? ''));
    $meta['cover_url']  = trim((string)($prefillTemplate['coverUrl'] ?? ''));
    $meta['status']     = $prefillTemplate['status'] ?? NOTES_DEFAULT_STATUS;
    $meta['properties'] = notes_normalize_properties($prefillTemplate['properties'] ?? []);
    $properties         = $meta['properties'];
    $tags               = $prefillTemplate['tags'] ?? [];
    $blocks             = $prefillTemplate['blocks'] ?? [];
}

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

$composerTemplates = array_map(static function (array $tpl): array {
    return [
        'id'          => (int)($tpl['id'] ?? 0),
        'name'        => (string)($tpl['name'] ?? ''),
        'title'       => (string)($tpl['title'] ?? ''),
        'icon'        => $tpl['icon'] ?? '',
        'coverUrl'    => $tpl['coverUrl'] ?? '',
        'status'      => $tpl['status'] ?? NOTES_DEFAULT_STATUS,
        'properties'  => $tpl['properties'] ?? notes_default_properties(),
        'tags'        => $tpl['tags'] ?? [],
        'blocks'      => $tpl['blocks'] ?? [],
        'shareIds'    => array_map(static fn($id) => (int)$id, $tpl['share_ids'] ?? []),
        'shareLabels' => array_map(static fn($label) => (string)$label, $tpl['share_labels'] ?? []),
    ];
}, $templates);

$composerConfig = [
    'blocks'           => $blocks,
    'tags'             => $tags,
    'icon'             => $meta['icon'],
    'coverUrl'         => $meta['cover_url'],
    'templates'        => $composerTemplates,
    'selectedTemplate' => $selectedTemplateId,
];
$formTitle        = $_POST['title'] ?? ($prefillTemplate['title'] ?? '');
$noteDateValue    = $_POST['note_date'] ?? $today;
$iconValue        = $_POST['icon'] ?? ($meta['icon'] ?? '');
$coverValue       = $_POST['cover_url'] ?? ($meta['cover_url'] ?? '');
$statusValue      = $_POST['status'] ?? ($meta['status'] ?? NOTES_DEFAULT_STATUS);
$propertyProject  = $_POST['property_project'] ?? ($meta['properties']['project'] ?? '');
$propertyLocation = $_POST['property_location'] ?? ($meta['properties']['location'] ?? '');
$propertyDueDate  = $_POST['property_due_date'] ?? ($meta['properties']['due_date'] ?? '');
$propertyPriority = $_POST['property_priority'] ?? ($meta['properties']['priority'] ?? 'Medium');
$bodyFallback     = $_POST['body'] ?? '';
$templateSharePreviewLabels = [];
if ($selectedTemplateId && isset($templateMap[$selectedTemplateId])) {
    $templateSharePreviewLabels = $templateMap[$selectedTemplateId]['share_labels'] ?? [];
}

$composerJson = json_encode($composerConfig, JSON_UNESCAPED_UNICODE);

$title = 'New Note';
include __DIR__ . '/../includes/header.php';
$configAttr = htmlspecialchars($composerJson, ENT_QUOTES, 'UTF-8');
?>
<section class="obsidian-shell obsidian-shell--composer" data-theme="obsidian">
  <header class="obsidian-header">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">New note</span>
      <h1>Create Obsidian-style page</h1>
      <p class="obsidian-header__subtitle">Capture blocks, properties, and attachments inside a dark vault workspace.</p>
    </div>
    <div class="obsidian-header__actions">
      <a class="btn obsidian-btn--ghost" href="index.php">Back to vault</a>
      <button class="btn obsidian-primary" type="submit" form="newNoteForm">Create note</button>
    </div>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="obsidian-form" id="newNoteForm" novalidate>
    <div class="obsidian-layout obsidian-layout--composer">
      <aside class="obsidian-sidebar obsidian-sidebar--composer">
        <?php if ($templates): ?>
        <section class="obsidian-panel obsidian-panel--templates">
          <header class="obsidian-panel__header">
            <h2>Templates</h2>
            <p>Start from a saved layout.</p>
          </header>
          <label class="obsidian-field">
            <span>Select template</span>
            <select name="template_select" data-template-select>
              <option value="">Blank page</option>
              <?php foreach ($templates as $tpl):
                $tplId = (int)($tpl['id'] ?? 0);
                $tplName = trim((string)($tpl['name'] ?? 'Untitled template'));
                $tplIcon = trim((string)($tpl['icon'] ?? ''));
                $tplIcon = $tplIcon !== '' ? $tplIcon : '📄';
                $tplShareLabels = $tpl['share_labels'] ?? [];
                $sharePayload = htmlspecialchars(json_encode($tplShareLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              ?>
              <option value="<?= $tplId; ?>" data-share="<?= $sharePayload; ?>"<?= $selectedTemplateId === $tplId ? ' selected' : ''; ?>>
                <?= sanitize($tplIcon . ' ' . $tplName); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="obsidian-template-actions">
            <button type="button" class="btn obsidian-btn" data-template-apply>Apply</button>
            <button type="button" class="btn obsidian-btn--ghost" data-template-clear>Reset</button>
          </div>
          <div class="obsidian-template-preview" data-template-share-preview>
            <?php if ($templateSharePreviewLabels): ?>
              <?php foreach ($templateSharePreviewLabels as $label): ?>
                <span class="obsidian-pill"><?= sanitize($label); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="obsidian-pill is-muted">Private</span>
            <?php endif; ?>
          </div>
          <ul class="obsidian-template-browser">
            <?php foreach ($templates as $tpl):
              $tplId = (int)($tpl['id'] ?? 0);
              $tplName = trim((string)($tpl['name'] ?? 'Untitled template'));
              $tplTitle = trim((string)($tpl['title'] ?? ''));
              $tplIcon = trim((string)($tpl['icon'] ?? ''));
              $tplIcon = $tplIcon !== '' ? $tplIcon : '📄';
              $shareLabels = $tpl['share_labels'] ?? [];
            ?>
            <li class="obsidian-template-card<?= $selectedTemplateId === $tplId ? ' is-active' : ''; ?>" data-template-card="<?= $tplId; ?>">
              <button type="button" class="obsidian-template-card__body" data-template-option="<?= $tplId; ?>">
                <span class="obsidian-template-card__icon"><?= sanitize($tplIcon); ?></span>
                <div>
                  <strong><?= sanitize($tplName); ?></strong>
                  <?php if ($tplTitle !== ''): ?><em><?= sanitize($tplTitle); ?></em><?php endif; ?>
                </div>
              </button>
              <div class="obsidian-template-card__shares">
                <?php if ($shareLabels): ?>
                  <?php foreach ($shareLabels as $label): ?>
                    <span class="obsidian-pill"><?= sanitize($label); ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="obsidian-pill is-muted">Private</span>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endif; ?>

        <section class="obsidian-panel obsidian-panel--meta">
          <header class="obsidian-panel__header">
            <h2>Note info</h2>
          </header>
          <label class="obsidian-field">
            <span>Date</span>
            <input type="date" name="note_date" value="<?= sanitize($noteDateValue); ?>" required>
          </label>
          <label class="obsidian-field">
            <span>Status</span>
            <select name="status">
              <?php foreach ($statuses as $slug => $label): ?>
                <option value="<?= sanitize($slug); ?>"<?= notes_normalize_status($statusValue) === $slug ? ' selected' : ''; ?>><?= sanitize($label); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="obsidian-panel obsidian-panel--tags" data-tag-section>
          <header class="obsidian-panel__header">
            <h2>Tags</h2>
          </header>
          <div class="obsidian-taglist" data-tag-list></div>
          <input type="text" class="obsidian-taginput" data-tag-input placeholder="Add tag and press Enter">
          <?php if ($tagOptions): ?>
          <div class="obsidian-tag-suggestions" aria-hidden="true">
            <?php foreach (array_slice($tagOptions, 0, 4) as $tag): ?>
              <span class="obsidian-pill"><?= sanitize('#' . $tag['label']); ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="obsidian-panel obsidian-panel--properties">
          <header class="obsidian-panel__header">
            <h2>Properties</h2>
          </header>
          <label class="obsidian-field">
            <span>Project</span>
            <input type="text" name="property_project" value="<?= sanitize($propertyProject); ?>" placeholder="Site or initiative">
          </label>
          <label class="obsidian-field">
            <span>Location</span>
            <input type="text" name="property_location" value="<?= sanitize($propertyLocation); ?>" placeholder="Area, floor, building">
          </label>
          <label class="obsidian-field">
            <span>Due date</span>
            <input type="date" name="property_due_date" value="<?= sanitize($propertyDueDate); ?>">
          </label>
          <label class="obsidian-field">
            <span>Priority</span>
            <select name="property_priority">
              <?php foreach ($priorityOptions as $option): ?>
                <option value="<?= sanitize($option); ?>"<?= $propertyPriority === $option ? ' selected' : ''; ?>><?= sanitize($option); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="obsidian-panel obsidian-panel--attachments">
          <header class="obsidian-panel__header">
            <h2>Attachments</h2>
            <p>Upload reference photos (max <?= (int)NOTES_MAX_MB; ?> MB each).</p>
          </header>
          <div class="obsidian-dropzone" id="dropZone" data-max-mb="<?= (int)NOTES_MAX_MB; ?>">
            <div class="obsidian-dropzone__icon">📎</div>
            <div class="obsidian-dropzone__copy">
              <strong>Drag & drop images</strong>
              <span>or use the slots below.</span>
            </div>
          </div>
          <div class="obsidian-uploader-grid" id="uploaderGrid">
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="obsidian-uploader" data-slot="<?= $i; ?>">
              <div class="obsidian-uploader__preview" id="preview<?= $i; ?>">
                <span class="obsidian-uploader__placeholder">Photo <?= $i; ?></span>
              </div>
              <div class="obsidian-uploader__actions">
                <label class="btn obsidian-btn">
                  Choose
                  <input id="photo<?= $i; ?>" type="file" name="photo<?= $i; ?>" accept="image/*,image/heic,image/heif" class="visually-hidden">
                </label>
                <button type="button" class="btn obsidian-btn--ghost" data-clear="<?= $i; ?>">Clear</button>
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </section>
      </aside>

      <div class="obsidian-main obsidian-main--composer">
        <div class="obsidian-editor" data-note-composer data-config="<?= $configAttr; ?>">
          <input type="hidden" name="blocks_payload" data-blocks-field>
          <input type="hidden" name="tags_payload" data-tags-field>
          <textarea name="body" data-body-fallback class="visually-hidden"><?= sanitize($bodyFallback); ?></textarea>

          <div class="obsidian-editor__cover" data-cover-preview>
            <div class="obsidian-editor__cover-overlay">
              <label class="obsidian-field">
                <span>Cover image URL</span>
                <input type="url" name="cover_url" data-cover-input placeholder="https://…" value="<?= sanitize($coverValue); ?>">
              </label>
              <button type="button" class="btn obsidian-btn--ghost small" data-cover-clear>Remove cover</button>
            </div>
          </div>

          <div class="obsidian-editor__head">
            <span class="obsidian-editor__icon" data-icon-preview><?= sanitize($iconValue ?: '📝'); ?></span>
            <div class="obsidian-editor__titlegroup">
              <label class="obsidian-field obsidian-field--icon">
                <span>Icon</span>
                <input type="text" name="icon" maxlength="4" data-icon-input placeholder="💡" value="<?= sanitize($iconValue); ?>">
              </label>
              <input class="obsidian-editor__title" type="text" name="title" value="<?= sanitize($formTitle); ?>" placeholder="Untitled" required>
            </div>
          </div>

          <section class="obsidian-editor__blocks">
            <header class="obsidian-editor__blocks-header">
              <h2>Content blocks</h2>
              <div class="obsidian-editor__toolbar" data-block-toolbar>
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
            <div class="obsidian-editor__blocks-list" data-block-list></div>
          </section>
        </div>

        <div class="obsidian-form__actions">
          <button class="btn obsidian-primary" type="submit">Create note</button>
          <a class="btn obsidian-btn--ghost" href="index.php">Cancel</a>
        </div>
      </div>
    </div>

    <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= csrf_token(); ?>">
  </form>
</section>

<style>
.obsidian-shell{position:relative;background:#fff;color:#0f172a;border-radius:20px;padding:1.4rem 1.6rem;margin-bottom:1.5rem;border:1px solid #e2e8f0;box-shadow:0 18px 40px rgba(15,23,42,.08);}
.obsidian-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.65rem;font-weight:700;color:#0f172a;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.14em;font-size:.72rem;color:#64748b;margin-bottom:.2rem;display:block;}
.obsidian-header__subtitle{margin:.25rem 0 0;color:#475569;max-width:46ch;font-size:.95rem;}
.obsidian-header__actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;}
.btn.obsidian-primary{background:#2563eb;border:none;color:#fff;border-radius:999px;padding:.5rem 1.2rem;font-weight:600;box-shadow:0 10px 20px rgba(37,99,235,.18);cursor:pointer;}
.btn.obsidian-btn{background:#e0e7ff;border:1px solid #c7d2fe;color:#1d4ed8;border-radius:999px;padding:.45rem .95rem;font-weight:500;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost{background:#fff;border:1px solid #cbd5f5;color:#1e293b;border-radius:999px;padding:.45rem .95rem;font-weight:500;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost.small{padding:.35rem .75rem;font-size:.8rem;}
.btn.obsidian-btn:hover{background:#c7d2fe;border-color:#94a3ff;color:#1e3a8a;}
.btn.obsidian-btn--ghost:hover{background:#f8fafc;border-color:#94a3b8;color:#0f172a;}
.obsidian-layout{display:grid;gap:1.25rem;grid-template-columns:280px minmax(0,1fr);align-items:start;}
.obsidian-sidebar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1.15rem;display:grid;gap:1.1rem;}
.obsidian-main{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:1.15rem;display:grid;gap:1.25rem;}
.obsidian-panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:.95rem 1rem;display:grid;gap:.75rem;}
.obsidian-panel__header{display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;flex-wrap:wrap;}
.obsidian-panel__header h2{margin:0;font-size:1rem;color:#0f172a;}
.obsidian-panel__hint{margin:.2rem 0 0;color:#64748b;font-size:.82rem;}
.obsidian-field{display:grid;gap:.35rem;font-size:.85rem;color:#1e293b;}
.obsidian-field span{text-transform:uppercase;letter-spacing:.08em;font-size:.7rem;color:#64748b;}
.obsidian-field input,.obsidian-field select{background:#fff;border:1px solid #cbd5f5;border-radius:.75rem;padding:.5rem .7rem;color:#0f172a;font-size:.93rem;}
.obsidian-field input:focus,.obsidian-field select:focus{outline:2px solid rgba(37,99,235,.35);outline-offset:1px;}
.obsidian-taglist{display:flex;gap:.35rem;flex-wrap:wrap;min-height:32px;}
.obsidian-taginput{background:#fff;border:1px dashed #cbd5f5;border-radius:.75rem;padding:.45rem .65rem;color:#334155;}
.obsidian-tag-suggestions{display:flex;gap:.3rem;font-size:.75rem;color:#64748b;flex-wrap:wrap;}
.obsidian-attachments{display:grid;gap:.65rem;}
.obsidian-attachment-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.65rem;display:grid;gap:.55rem;}
.obsidian-attachment-card__preview{border:1px dashed #cbd5f5;border-radius:10px;min-height:110px;display:grid;place-items:center;color:#94a3b8;overflow:hidden;background:#f8fafc;}
.obsidian-attachment-card__preview img{width:100%;height:100%;object-fit:cover;}
.obsidian-attachment-card__actions{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;}
.obsidian-upload-form{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;}
.obsidian-detail__shares{display:flex;gap:.3rem;flex-wrap:wrap;}
.obsidian-pill{display:inline-flex;align-items:center;background:#e0e7ff;border-radius:999px;padding:.25rem .6rem;font-size:.78rem;color:#1e3a8a;font-weight:500;}
.obsidian-pill.is-muted{background:#edf2f7;color:#64748b;}
.obsidian-muted{color:#64748b;}
.obsidian-editor{background:#fff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;display:grid;gap:0;}
.obsidian-editor__cover{position:relative;height:160px;background:linear-gradient(135deg,#bfdbfe,#f8fafc);background-size:cover;background-position:center;}
.obsidian-editor__cover.has-cover{background-size:cover;}
.obsidian-editor__cover-overlay{position:absolute;inset:0;display:flex;justify-content:space-between;align-items:flex-end;padding:.8rem;background:linear-gradient(180deg,rgba(248,250,252,.05),rgba(15,23,42,.15));gap:.6rem;}
.obsidian-editor__head{display:flex;gap:.9rem;align-items:flex-start;padding:1rem 1.1rem 0;}
.obsidian-editor__icon{width:48px;height:48px;border-radius:12px;background:#e0e7ff;display:grid;place-items:center;font-size:1.3rem;color:#1d4ed8;}
.obsidian-editor__titlegroup{display:grid;gap:.5rem;flex:1;}
.obsidian-editor__title{background:transparent;border:none;border-bottom:1px solid #dbeafe;padding:.25rem 0;font-size:1.6rem;font-weight:600;color:#0f172a;}
.obsidian-editor__title:focus{outline:none;border-color:#2563eb;}
.obsidian-editor__blocks{padding:1.1rem;display:grid;gap:.85rem;}
.obsidian-editor__blocks-header{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;}
.obsidian-editor__blocks-header h2{margin:0;font-size:1.05rem;color:#0f172a;}
.obsidian-editor__toolbar{display:flex;gap:.4rem;flex-wrap:wrap;}
.chip{background:#e0f2fe;border:1px solid #bae6fd;color:#0369a1;border-radius:999px;padding:.25rem .6rem;font-size:.8rem;cursor:pointer;transition:background .2s ease;}
.chip:hover{background:#bae6fd;}
.obsidian-editor__blocks-list{display:grid;gap:.85rem;}
.composer-block{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:.85rem;display:grid;gap:.65rem;}
.composer-block__head{display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;}
.composer-block__type{background:#e0e7ff;border:1px solid #c7d2fe;color:#1d4ed8;border-radius:999px;padding:.3rem .6rem;font-size:.75rem;}
.composer-block__actions{display:flex;gap:.3rem;}
.composer-block__btn{background:#e2e8f0;border:1px solid #cbd5f5;color:#334155;border-radius:.45rem;padding:.25rem .55rem;cursor:pointer;font-size:.78rem;}
.composer-block__btn--danger{background:#fee2e2;border-color:#fecaca;color:#b91c1c;}
.composer-block__btn:disabled{opacity:.4;cursor:not-allowed;}
.composer-block__text{background:#fff;border:1px solid #dbeafe;border-radius:.7rem;padding:.65rem .75rem;color:#0f172a;min-height:100px;resize:vertical;font-size:.95rem;}
.composer-block__checkbox{display:flex;gap:.45rem;align-items:flex-start;color:#334155;}
.composer-block__icon-input{display:grid;gap:.3rem;color:#475569;}
.composer-block__icon-input input{background:#fff;border:1px solid #cbd5f5;border-radius:.65rem;padding:.45rem .6rem;color:#0f172a;}
.composer-block__hint{margin:0;font-size:.74rem;color:#94a3b8;}
.composer-block__divider{height:2px;background:linear-gradient(90deg,#cbd5f5,#e2e8f0);border-radius:999px;}
.obsidian-form__actions{display:flex;justify-content:flex-end;gap:.6rem;flex-wrap:wrap;}
.obsidian-template-browser{display:grid;gap:.5rem;}
.obsidian-template-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.6rem .7rem;display:grid;gap:.45rem;}
.obsidian-template-card__body{display:flex;gap:.5rem;align-items:center;}
.obsidian-template-card__icon{width:32px;height:32px;border-radius:9px;background:#e0e7ff;display:grid;place-items:center;font-size:1rem;color:#1d4ed8;}
.obsidian-template-card__body strong{display:block;font-weight:600;color:#0f172a;}
.obsidian-template-card__body em{display:block;font-size:.76rem;color:#64748b;font-style:normal;}
.obsidian-template-card__shares{display:flex;gap:.3rem;flex-wrap:wrap;}
.obsidian-panel--danger{border:1px solid #fecaca;background:#fef2f2;}
.obsidian-panel--danger button{color:#b91c1c;}
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
.obsidian-modal__body{padding:.9rem 1.05rem;display:grid;gap:.75rem;max-height:320px;overflow:auto;}
.obsidian-modal__search input{width:100%;background:#fff;border:1px solid #cbd5f5;border-radius:.75rem;padding:.55rem .75rem;color:#0f172a;font-size:.95rem;}
.obsidian-modal__options{display:grid;gap:.55rem;}
.obsidian-modal__option{display:flex;gap:.55rem;align-items:center;padding:.55rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;cursor:pointer;transition:border-color .2s ease,background .2s ease;}
.obsidian-modal__option:hover{border-color:#2563eb;}
.obsidian-modal__option input{width:1rem;height:1rem;}
.obsidian-modal__option.is-disabled{opacity:.6;cursor:not-allowed;}
.obsidian-modal__option.is-disabled input{pointer-events:none;}
.obsidian-modal__badge{margin-left:auto;font-size:.72rem;color:#64748b;background:#edf2f7;border-radius:999px;padding:.2rem .5rem;}
.obsidian-modal__empty{margin:0;font-size:.8rem;color:#64748b;}
.obsidian-modal__footer{padding:.85rem 1.05rem;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;}
.obsidian-modal__status{font-size:.8rem;color:#64748b;min-height:1.1rem;}
.obsidian-modal__status.is-error{color:#b91c1c;}
.flash{border-radius:10px;padding:.75rem .95rem;margin-bottom:1rem;font-weight:500;}
.flash-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;}
.visually-hidden{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;}
@media (max-width:1080px){.obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar,.obsidian-main{grid-column:1 / -1;}}
@media (max-width:720px){.obsidian-shell{padding:1.25rem;} .obsidian-header__titles h1{font-size:1.45rem;} .obsidian-editor__head{flex-direction:column;align-items:flex-start;} .obsidian-editor__title{font-size:1.4rem;}}
</style>

<script src="composer.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const dropZone = document.getElementById('dropZone');
  const maxMB = dropZone ? parseInt(dropZone.dataset.maxMb || '70', 10) : 70;
  const maxBytes = maxMB * 1024 * 1024;
  const inputs = [1, 2, 3].map((i) => document.getElementById('photo' + i));
  const previews = [1, 2, 3].map((i) => document.getElementById('preview' + i));

  function clearSlot(i) {
    const input = inputs[i - 1];
    const preview = previews[i - 1];
    if (!input || !preview) return;
    try {
      const dt = new DataTransfer();
      input.files = dt.files;
    } catch (err) {
      input.value = '';
    }
    preview.innerHTML = '<span class="obsidian-uploader__placeholder">Photo ' + i + '</span>';
  }

  function showPreview(i, file) {
    const preview = previews[i - 1];
    if (!preview) return;
    const reader = new FileReader();
    reader.onload = (event) => {
      preview.innerHTML = '';
      const img = document.createElement('img');
      img.src = event.target?.result || '';
      img.alt = 'Preview ' + i;
      preview.appendChild(img);
    };
    reader.readAsDataURL(file);
  }

  function setFileToInput(input, file) {
    if (!file) return false;
    if (file.size > maxBytes) {
      alert('File "' + file.name + '" is too large. Max ' + maxMB + 'MB.');
      return false;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return true;
  }

  function firstEmptyInputIdx() {
    for (let i = 0; i < inputs.length; i += 1) {
      const input = inputs[i];
      if (!input || !input.files || input.files.length === 0) return i;
    }
    return -1;
  }

  inputs.forEach((input, idx) => {
    if (!input) return;
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) {
        clearSlot(idx + 1);
        return;
      }
      showPreview(idx + 1, file);
    });
  });

  document.querySelectorAll('[data-clear]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const slot = parseInt(btn.getAttribute('data-clear') || '0', 10);
      if (!slot) return;
      clearSlot(slot);
    });
  });

  if (dropZone) {
    dropZone.addEventListener('dragover', (event) => {
      event.preventDefault();
      dropZone.classList.add('is-drag');
    });
    dropZone.addEventListener('dragleave', (event) => {
      event.preventDefault();
      dropZone.classList.remove('is-drag');
    });
    dropZone.addEventListener('drop', (event) => {
      event.preventDefault();
      dropZone.classList.remove('is-drag');
      const files = Array.from(event.dataTransfer?.files || []);
      files.forEach((file) => {
        const idx = firstEmptyInputIdx();
        if (idx === -1) return;
        const input = inputs[idx];
        if (input && setFileToInput(input, file)) {
          showPreview(idx + 1, file);
        }
      });
    });
  }

  const composerEl = document.querySelector('[data-note-composer]');
  const templateSelect = document.querySelector('[data-template-select]');
  const templateButtons = Array.from(document.querySelectorAll('[data-template-option]'));
  const templateClear = document.querySelector('[data-template-clear]');
  const templatePreview = document.querySelector('[data-template-share-preview]');
  const templateCards = Array.from(document.querySelectorAll('[data-template-card]'));
  const templateMeta = new Map();
  let initialTemplate = '';

  if (composerEl) {
    try {
      const raw = composerEl.getAttribute('data-config') || '{}';
      const parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.templates)) {
        parsed.templates.forEach((tpl) => {
          if (!tpl || typeof tpl.id === 'undefined') return;
          templateMeta.set(String(tpl.id), tpl);
        });
      }
      if (parsed && parsed.selectedTemplate) {
        initialTemplate = String(parsed.selectedTemplate);
      }
    } catch (err) {
      console.warn('Composer config parse error', err);
    }
  }

  function renderShareFromTemplateId(id) {
    if (!templatePreview) return;
    templatePreview.innerHTML = '';
    const tpl = templateMeta.get(String(id));
    const labels = tpl && Array.isArray(tpl.shareLabels) ? tpl.shareLabels : [];
    if (!labels.length) {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill is-muted';
      pill.textContent = 'Private';
      templatePreview.appendChild(pill);
      return;
    }
    labels.forEach((label) => {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill';
      pill.textContent = label;
      templatePreview.appendChild(pill);
    });
  }

  function syncTemplateCards(activeId) {
    templateCards.forEach((card) => {
      const cardId = card.getAttribute('data-template-card') || '';
      card.classList.toggle('is-active', activeId !== '' && cardId === String(activeId));
    });
  }

  if (templateSelect) {
    templateSelect.addEventListener('change', () => {
      const value = templateSelect.value;
      renderShareFromTemplateId(value);
      syncTemplateCards(value);
    });
  }

  templateButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const id = button.getAttribute('data-template-option') || '';
      if (templateSelect) {
        templateSelect.value = id;
        templateSelect.dispatchEvent(new Event('change', { bubbles: true }));
      } else {
        renderShareFromTemplateId(id);
        syncTemplateCards(id);
      }
    });
  });

  if (templateClear) {
    templateClear.addEventListener('click', () => {
      if (templateSelect) {
        templateSelect.value = '';
      }
      renderShareFromTemplateId('');
      syncTemplateCards('');
    });
  }

  if (templateSelect) {
    if (templateSelect.value) {
      renderShareFromTemplateId(templateSelect.value);
      syncTemplateCards(templateSelect.value);
    } else if (initialTemplate) {
      renderShareFromTemplateId(initialTemplate);
      syncTemplateCards(initialTemplate);
    } else {
      renderShareFromTemplateId('');
      syncTemplateCards('');
    }
  } else {
    if (initialTemplate) {
      renderShareFromTemplateId(initialTemplate);
      syncTemplateCards(initialTemplate);
    } else {
      renderShareFromTemplateId('');
      syncTemplateCards('');
    }
  }
});
</script>
