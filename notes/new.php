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
.obsidian-shell{position:relative;background:radial-gradient(circle at top left,#1e293b,#0f172a);color:#e2e8f0;border-radius:24px;padding:2rem 2.25rem;margin-bottom:2rem;box-shadow:0 30px 60px rgba(15,23,42,.35);}
.obsidian-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1.25rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.9rem;font-weight:700;color:#f8fafc;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:.78rem;color:#94a3b8;display:block;margin-bottom:.25rem;}
.obsidian-header__subtitle{margin:.35rem 0 0;color:#cbd5f5;max-width:46ch;}
.obsidian-header__actions{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;}
.btn.obsidian-primary{background:#6366f1;border:none;color:#f8fafc;border-radius:999px;padding:.6rem 1.4rem;font-weight:600;box-shadow:0 14px 30px rgba(99,102,241,.35);cursor:pointer;}
.btn.obsidian-btn{background:rgba(99,102,241,.25);border:1px solid rgba(99,102,241,.45);color:#e0e7ff;border-radius:999px;padding:.45rem 1rem;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost{background:transparent;border:1px solid rgba(148,163,184,.35);color:#cbd5f5;border-radius:999px;padding:.45rem 1rem;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;}
.btn.obsidian-btn--ghost.small{padding:.35rem .9rem;font-size:.82rem;}
.btn.obsidian-btn:hover{background:rgba(99,102,241,.35);}
.btn.obsidian-btn--ghost:hover{border-color:rgba(148,163,184,.6);color:#f8fafc;}
.obsidian-layout{display:grid;gap:1.5rem;grid-template-columns:320px minmax(0,1fr);align-items:start;}
.obsidian-sidebar{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.14);border-radius:18px;padding:1.4rem;display:grid;gap:1.4rem;}
.obsidian-main{background:rgba(15,23,42,.35);border:1px solid rgba(148,163,184,.1);border-radius:18px;padding:1.35rem;display:grid;gap:1.5rem;}
.obsidian-panel{background:rgba(15,23,42,.5);border:1px solid rgba(148,163,184,.16);border-radius:16px;padding:1.1rem 1.2rem;display:grid;gap:.9rem;}
.obsidian-panel__header h2{margin:0;font-size:1.05rem;color:#f8fafc;}
.obsidian-panel__header p{margin:.3rem 0 0;font-size:.85rem;color:#94a3b8;}
.obsidian-field{display:grid;gap:.4rem;font-size:.88rem;color:#cbd5f5;}
.obsidian-field span{text-transform:uppercase;letter-spacing:.1em;font-size:.72rem;color:#94a3b8;}
.obsidian-field input,.obsidian-field select{background:rgba(15,23,42,.65);border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:.55rem .75rem;color:#f8fafc;font-size:.95rem;}
.obsidian-field input:focus,.obsidian-field select:focus{outline:2px solid rgba(99,102,241,.6);outline-offset:1px;}
.obsidian-template-actions{display:flex;gap:.5rem;flex-wrap:wrap;}
.obsidian-template-preview{display:flex;gap:.4rem;flex-wrap:wrap;}
.obsidian-template-browser{list-style:none;margin:0;padding:0;display:grid;gap:.6rem;}
.obsidian-template-card{background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:.65rem .75rem;display:grid;gap:.5rem;transition:border-color .2s ease,box-shadow .2s ease;}
.obsidian-template-card.is-active{border-color:#6366f1;box-shadow:0 12px 26px rgba(99,102,241,.35);}
.obsidian-template-card__body{display:flex;gap:.65rem;align-items:center;text-align:left;background:none;border:none;color:#f8fafc;cursor:pointer;padding:0;}
.obsidian-template-card__body strong{display:block;font-weight:600;}
.obsidian-template-card__body em{display:block;font-size:.78rem;color:#94a3b8;font-style:normal;}
.obsidian-template-card__icon{width:34px;height:34px;border-radius:10px;background:rgba(99,102,241,.2);display:grid;place-items:center;font-size:1.1rem;}
.obsidian-template-card__shares{display:flex;gap:.35rem;flex-wrap:wrap;}
.obsidian-pill{display:inline-flex;align-items:center;background:rgba(99,102,241,.25);border-radius:999px;padding:.25rem .65rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-pill.is-muted{background:rgba(148,163,184,.18);color:#cbd5f5;}
.obsidian-taglist{display:flex;gap:.4rem;flex-wrap:wrap;min-height:34px;}
.obsidian-taginput{background:rgba(15,23,42,.65);border:1px dashed rgba(148,163,184,.35);border-radius:12px;padding:.5rem .75rem;color:#f8fafc;}
.obsidian-tag-suggestions{display:flex;gap:.35rem;font-size:.78rem;color:#94a3b8;flex-wrap:wrap;}
.obsidian-dropzone{border:2px dashed rgba(148,163,184,.35);border-radius:16px;padding:1rem;display:flex;gap:.85rem;align-items:center;background:rgba(15,23,42,.6);transition:border-color .2s ease,background .2s ease;}
.obsidian-dropzone.is-drag{border-color:#6366f1;background:rgba(99,102,241,.25);}
.obsidian-dropzone__icon{font-size:1.6rem;}
.obsidian-dropzone__copy strong{display:block;}
.obsidian-dropzone__copy span{font-size:.85rem;color:#94a3b8;}
.obsidian-uploader-grid{display:grid;gap:.9rem;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));}
.obsidian-uploader{background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.16);border-radius:14px;padding:.75rem;display:grid;gap:.6rem;}
.obsidian-uploader__preview{border:1px dashed rgba(148,163,184,.3);border-radius:12px;min-height:120px;display:grid;place-items:center;color:#94a3b8;overflow:hidden;background:rgba(15,23,42,.35);}
.obsidian-uploader__preview img{width:100%;height:100%;object-fit:cover;}
.obsidian-uploader__actions{display:flex;gap:.5rem;flex-wrap:wrap;}
.obsidian-editor{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.18);border-radius:18px;overflow:hidden;display:grid;gap:0;}
.obsidian-editor__cover{position:relative;height:180px;background:linear-gradient(135deg,#312e81,#0f172a);background-size:cover;background-position:center;}
.obsidian-editor__cover.has-cover{background-size:cover;}
.obsidian-editor__cover-overlay{position:absolute;inset:0;display:flex;justify-content:space-between;align-items:flex-end;padding:1.1rem;background:linear-gradient(180deg,rgba(15,23,42,.05),rgba(15,23,42,.55));gap:.75rem;}
.obsidian-editor__head{display:flex;gap:1rem;align-items:flex-start;padding:1.2rem 1.4rem 0;}
.obsidian-editor__icon{width:52px;height:52px;border-radius:14px;background:rgba(99,102,241,.25);display:grid;place-items:center;font-size:1.4rem;}
.obsidian-editor__titlegroup{display:grid;gap:.6rem;flex:1;}
.obsidian-editor__title{background:transparent;border:none;border-bottom:1px solid rgba(148,163,184,.25);padding:.3rem 0;font-size:2rem;font-weight:600;color:#f8fafc;}
.obsidian-editor__title:focus{outline:none;border-color:#6366f1;}
.obsidian-editor__blocks{padding:1.4rem;display:grid;gap:1rem;}
.obsidian-editor__blocks-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;}
.obsidian-editor__blocks-header h2{margin:0;font-size:1.1rem;color:#f8fafc;}
.obsidian-editor__toolbar{display:flex;gap:.45rem;flex-wrap:wrap;}
.chip{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);color:#e0e7ff;border-radius:999px;padding:.3rem .7rem;font-size:.85rem;cursor:pointer;transition:background .2s ease;}
.chip:hover{background:rgba(99,102,241,.35);}
.obsidian-editor__blocks-list{display:grid;gap:1rem;}
.composer-block{background:rgba(15,23,42,.65);border:1px solid rgba(148,163,184,.2);border-radius:14px;padding:1rem;display:grid;gap:.75rem;}
.composer-block__head{display:flex;justify-content:space-between;align-items:center;gap:.75rem;}
.composer-block__type{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.35);color:#e0e7ff;border-radius:999px;padding:.35rem .65rem;font-size:.78rem;}
.composer-block__actions{display:flex;gap:.35rem;}
.composer-block__btn{background:rgba(148,163,184,.18);border:1px solid rgba(148,163,184,.35);color:#e2e8f0;border-radius:.5rem;padding:.3rem .6rem;cursor:pointer;font-size:.82rem;}
.composer-block__btn--danger{background:rgba(239,68,68,.18);border-color:rgba(248,113,113,.35);color:#fecaca;}
.composer-block__btn:disabled{opacity:.4;cursor:not-allowed;}
.composer-block__text{background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.35);border-radius:.9rem;padding:.7rem .85rem;color:#f8fafc;min-height:110px;resize:vertical;}
.composer-block__checkbox{display:flex;gap:.5rem;align-items:flex-start;color:#e2e8f0;}
.composer-block__icon-input{display:grid;gap:.35rem;color:#cbd5f5;}
.composer-block__icon-input input{background:rgba(15,23,42,.75);border:1px solid rgba(148,163,184,.35);border-radius:.75rem;padding:.5rem .7rem;color:#f8fafc;}
.composer-block__hint{margin:0;font-size:.78rem;color:#94a3b8;}
.composer-block__divider{height:2px;background:linear-gradient(90deg,rgba(99,102,241,.35),rgba(148,163,184,.25));border-radius:999px;}
.obsidian-form__actions{display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;}
.visually-hidden{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;}
.flash{border-radius:12px;padding:.85rem 1.1rem;margin-bottom:1rem;}
.flash-error{background:rgba(239,68,68,.15);border:1px solid rgba(248,113,113,.3);color:#fecaca;}
@media (max-width:1080px){.obsidian-layout{grid-template-columns:minmax(0,1fr);} .obsidian-sidebar,.obsidian-main{grid-column:1 / -1;}}
@media (max-width:720px){.obsidian-shell{padding:1.5rem;} .obsidian-header__titles h1{font-size:1.5rem;} .obsidian-editor__head{flex-direction:column;align-items:flex-start;} .obsidian-editor__title{font-size:1.6rem;}}
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
