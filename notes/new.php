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
    <div class="flash flash-error"><?php echo  sanitize(implode(' ', $errors)); ?></div>
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
              <option value="<?php echo  $tplId; ?>" data-share="<?php echo  $sharePayload; ?>"<?php echo  $selectedTemplateId === $tplId ? ' selected' : ''; ?>>
                <?php echo  sanitize($tplIcon . ' ' . $tplName); ?>
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
                <span class="obsidian-pill"><?php echo  sanitize($label); ?></span>
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
            <li class="obsidian-template-card<?php echo  $selectedTemplateId === $tplId ? ' is-active' : ''; ?>" data-template-card="<?php echo  $tplId; ?>">
              <button type="button" class="obsidian-template-card__body" data-template-option="<?php echo  $tplId; ?>">
                <span class="obsidian-template-card__icon"><?php echo  sanitize($tplIcon); ?></span>
                <div>
                  <strong><?php echo  sanitize($tplName); ?></strong>
                  <?php if ($tplTitle !== ''): ?><em><?php echo  sanitize($tplTitle); ?></em><?php endif; ?>
                </div>
              </button>
              <div class="obsidian-template-card__shares">
                <?php if ($shareLabels): ?>
                  <?php foreach ($shareLabels as $label): ?>
                    <span class="obsidian-pill"><?php echo  sanitize($label); ?></span>
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
            <input type="date" name="note_date" value="<?php echo  sanitize($noteDateValue); ?>" required>
          </label>
          <label class="obsidian-field">
            <span>Status</span>
            <select name="status">
              <?php foreach ($statuses as $slug => $label): ?>
                <option value="<?php echo  sanitize($slug); ?>"<?php echo  notes_normalize_status($statusValue) === $slug ? ' selected' : ''; ?>><?php echo  sanitize($label); ?></option>
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
              <span class="obsidian-pill"><?php echo  sanitize('#' . $tag['label']); ?></span>
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
            <input type="text" name="property_project" value="<?php echo  sanitize($propertyProject); ?>" placeholder="Site or initiative">
          </label>
          <label class="obsidian-field">
            <span>Location</span>
            <input type="text" name="property_location" value="<?php echo  sanitize($propertyLocation); ?>" placeholder="Area, floor, building">
          </label>
          <label class="obsidian-field">
            <span>Due date</span>
            <input type="date" name="property_due_date" value="<?php echo  sanitize($propertyDueDate); ?>">
          </label>
          <label class="obsidian-field">
            <span>Priority</span>
            <select name="property_priority">
              <?php foreach ($priorityOptions as $option): ?>
                <option value="<?php echo  sanitize($option); ?>"<?php echo  $propertyPriority === $option ? ' selected' : ''; ?>><?php echo  sanitize($option); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </section>

        <section class="obsidian-panel obsidian-panel--attachments">
          <header class="obsidian-panel__header">
            <h2>Attachments</h2>
            <p>Upload reference photos (max <?php echo  (int)NOTES_MAX_MB; ?> MB each).</p>
          </header>
          <div class="obsidian-dropzone" id="dropZone" data-max-mb="<?php echo  (int)NOTES_MAX_MB; ?>">
            <div class="obsidian-dropzone__icon">📎</div>
            <div class="obsidian-dropzone__copy">
              <strong>Drag & drop images</strong>
              <span>or use the slots below.</span>
            </div>
          </div>
          <div class="obsidian-uploader-grid" id="uploaderGrid">
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="obsidian-uploader" data-slot="<?php echo  $i; ?>">
              <div class="obsidian-uploader__preview" id="preview<?php echo  $i; ?>">
                <span class="obsidian-uploader__placeholder">Photo <?php echo  $i; ?></span>
              </div>
              <div class="obsidian-uploader__actions">
                <label class="btn obsidian-btn">
                  Choose
                  <input id="photo<?php echo  $i; ?>" type="file" name="photo<?php echo  $i; ?>" accept="image/*,image/heic,image/heif" class="visually-hidden">
                </label>
                <button type="button" class="btn obsidian-btn--ghost" data-clear="<?php echo  $i; ?>">Clear</button>
              </div>
            </div>
            <?php endfor; ?>
          </div>
        </section>
      </aside>

      <div class="obsidian-main obsidian-main--composer">
        <div class="obsidian-editor" data-note-composer data-config="<?php echo  $configAttr; ?>">
          <input type="hidden" name="blocks_payload" data-blocks-field>
          <input type="hidden" name="tags_payload" data-tags-field>
          <textarea name="body" data-body-fallback class="visually-hidden"><?php echo  sanitize($bodyFallback); ?></textarea>

          <div class="obsidian-editor__cover" data-cover-preview>
            <div class="obsidian-editor__cover-overlay">
              <label class="obsidian-field">
                <span>Cover image URL</span>
                <input type="url" name="cover_url" data-cover-input placeholder="https://…" value="<?php echo  sanitize($coverValue); ?>">
              </label>
              <button type="button" class="btn obsidian-btn--ghost small" data-cover-clear>Remove cover</button>
            </div>
          </div>

          <div class="obsidian-editor__head">
            <span class="obsidian-editor__icon" data-icon-preview><?php echo  sanitize($iconValue ?: '📝'); ?></span>
            <div class="obsidian-editor__titlegroup">
              <label class="obsidian-field obsidian-field--icon">
                <span>Icon</span>
                <input type="text" name="icon" maxlength="4" data-icon-input placeholder="💡" value="<?php echo  sanitize($iconValue); ?>">
              </label>
              <input class="obsidian-editor__title" type="text" name="title" value="<?php echo  sanitize($formTitle); ?>" placeholder="Untitled" required>
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

    <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  csrf_token(); ?>">
  </form>
</section>

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
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 34px 68px var(--surface-shadow);
  margin-bottom: 2rem;
}

.amanote-grid {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 1.4rem;
}

.amanote-main {
  display: grid;
  gap: 1.2rem;
}

.amanote-rail {
  display: grid;
  grid-template-rows: auto auto 1fr auto;
  gap: 1.2rem;
  background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(248,250,252,0.95));
  border-radius: 22px;
  border: 1px solid var(--surface-border);
  padding: 1.2rem 1.1rem;
  box-shadow: 0 22px 40px rgba(15,23,42,0.08);
}

.amanote-rail__brand {
  display: flex;
  gap: 0.8rem;
  align-items: center;
}

.amanote-rail__glyph {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: var(--surface-accent);
  display: grid;
  place-items: center;
  color: #fff;
  font-size: 1.4rem;
}

.amanote-rail__brand strong {
  display: block;
  font-size: 1.05rem;
  color: var(--surface-strong);
}

.amanote-rail__brand small {
  display: block;
  font-size: 0.75rem;
  color: var(--surface-muted);
}

.amanote-rail__statuses {
  display: grid;
  gap: 0.55rem;
}

.amanote-rail__status {
  display: flex;
  gap: 0.8rem;
  align-items: center;
  text-decoration: none;
  padding: 0.5rem 0.55rem;
  border-radius: 14px;
  color: var(--surface-strong);
  border: 1px solid transparent;
  transition: all 0.18s ease;
}

.amanote-rail__status:hover {
  border-color: rgba(37,99,235,0.25);
  background: rgba(37,99,235,0.08);
}

.amanote-rail__status.is-active {
  border-color: var(--surface-accent);
  background: var(--surface-accent-soft);
  box-shadow: inset 0 0 0 1px rgba(37,99,235,0.2);
}

.amanote-rail__icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: rgba(37,99,235,0.14);
  display: grid;
  place-items: center;
  font-size: 1.1rem;
  color: var(--surface-accent);
}

.amanote-rail__status strong {
  display: block;
  font-size: 0.95rem;
}

.amanote-rail__status small {
  display: block;
  font-size: 0.72rem;
  color: var(--surface-muted);
}

.amanote-rail__tags {
  background: rgba(241,245,249,0.9);
  border-radius: 18px;
  border: 1px dashed rgba(148,163,184,0.4);
  padding: 0.85rem;
  display: grid;
  gap: 0.65rem;
}

.amanote-rail__tags-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.amanote-rail__tags h3 {
  margin: 0;
  font-size: 0.9rem;
  color: var(--surface-strong);
}

.amanote-rail__clear {
  font-size: 0.75rem;
  color: var(--surface-accent);
  text-decoration: none;
}

.amanote-rail__tags ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.amanote-rail__tag {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.45rem 0.55rem;
  border-radius: 12px;
  text-decoration: none;
  color: var(--surface-strong);
  border: 1px solid transparent;
  transition: all 0.18s ease;
}

.amanote-rail__tag:hover {
  border-color: rgba(37,99,235,0.25);
  background: rgba(37,99,235,0.08);
}

.amanote-rail__tag.is-active {
  border-color: var(--surface-accent);
  background: var(--surface-accent-soft);
}

.amanote-rail__tag small {
  margin-left: auto;
  font-size: 0.72rem;
  color: var(--surface-muted);
}

.amanote-rail__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--tag-color, var(--surface-accent));
}

.amanote-rail__empty {
  margin: 0;
  font-size: 0.76rem;
  color: var(--surface-muted);
}

.amanote-rail__quick {
  background: var(--surface-accent);
  color: #fff;
  border: none;
  border-radius: 999px;
  padding: 0.55rem 1.1rem;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 16px 30px rgba(37,99,235,0.32);
}
.obsidian-header {
  background: var(--surface-panel);
  border-radius: 20px;
  border: 1px solid var(--surface-border);
  padding: 1.2rem 1.4rem;
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  box-shadow: 0 20px 46px var(--surface-shadow);
}

.obsidian-header__titles {
  display: grid;
  gap: 0.45rem;
}

.obsidian-header__eyebrow {
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-size: 0.7rem;
  color: var(--surface-muted);
}

.obsidian-header__titles h1 {
  margin: 0;
  font-size: 1.6rem;
  color: var(--surface-strong);
}

.obsidian-header__subtitle {
  margin: 0;
  font-size: 0.85rem;
  color: var(--surface-muted);
  max-width: 520px;
}

.obsidian-header__actions {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.obsidian-header__command,
.obsidian-header__quick,
.obsidian-header__new {
  border-radius: 999px;
  padding: 0.5rem 1.1rem;
  font-weight: 600;
  font-size: 0.9rem;
  border: 1px solid transparent;
  cursor: pointer;
  text-decoration: none;
  transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.obsidian-header__command {
  background: #fff;
  border-color: rgba(148,163,184,0.45);
  color: var(--surface-strong);
}

.obsidian-header__command:hover {
  border-color: var(--surface-accent);
  color: var(--surface-accent);
}

.obsidian-header__quick {
  background: var(--surface-accent-soft);
  border-color: rgba(37,99,235,0.2);
  color: var(--surface-accent);
}

.obsidian-header__new {
  background: var(--surface-accent);
  color: #fff;
  box-shadow: 0 18px 38px rgba(37,99,235,0.26);
}

.obsidian-header__actions > *:hover {
  transform: translateY(-1px);
}

.obsidian-layout {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr) 320px;
  gap: 1.2rem;
}

.obsidian-sidebar,
.obsidian-main,
.obsidian-preview {
  background: var(--surface-panel);
  border-radius: 20px;
  border: 1px solid var(--surface-border);
  padding: 1.2rem;
  box-shadow: 0 18px 36px var(--surface-shadow);
  display: grid;
  gap: 1.05rem;
}

.obsidian-search {
  display: grid;
  gap: 0.7rem;
}

.obsidian-search__field span {
  display: block;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--surface-muted);
  margin-bottom: 0.35rem;
}

.obsidian-search input {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(148,163,184,0.45);
  padding: 0.6rem 0.85rem;
  font-size: 0.95rem;
  color: var(--surface-strong);
}

.obsidian-search__meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.78rem;
  color: var(--surface-muted);
}

.obsidian-search__hint kbd {
  background: rgba(226,232,240,0.8);
  border-radius: 6px;
  padding: 0.15rem 0.35rem;
  font-family: "JetBrains Mono", monospace;
  font-size: 0.7rem;
}

.obsidian-search__chip {
  background: rgba(226,232,240,0.9);
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  color: var(--surface-strong);
  font-size: 0.74rem;
}

.obsidian-search__reset {
  color: var(--surface-accent);
  text-decoration: none;
}

.obsidian-summary {
  background: rgba(248,250,252,0.9);
  border-radius: 16px;
  border: 1px dashed rgba(148,163,184,0.45);
  padding: 0.9rem;
  display: grid;
  gap: 0.65rem;
}

.obsidian-summary span {
  display: block;
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  color: var(--surface-muted);
  text-transform: uppercase;
}

.obsidian-summary strong {
  display: block;
  font-size: 1rem;
  color: var(--surface-strong);
}

.obsidian-statuses ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.obsidian-statuses li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: rgba(248,250,252,0.95);
  border-radius: 12px;
  padding: 0.45rem 0.6rem;
  border: 1px solid rgba(226,232,240,0.9);
  font-size: 0.85rem;
  color: var(--surface-strong);
}

.obsidian-templates header h2 {
  margin: 0;
  font-size: 1rem;
  color: var(--surface-strong);
}

.obsidian-templates header p {
  margin: 0.35rem 0 0;
  color: var(--surface-muted);
  font-size: 0.82rem;
}

.obsidian-templates h3 {
  margin: 0.6rem 0 0.35rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  font-size: 0.7rem;
  color: var(--surface-muted);
}

.obsidian-template__empty {
  margin: 0;
  font-size: 0.78rem;
  color: var(--surface-muted);
}

.obsidian-templates ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.55rem;
}

.obsidian-template {
  background: rgba(248,250,252,0.9);
  border-radius: 14px;
  border: 1px solid rgba(226,232,240,0.85);
  padding: 0.65rem 0.7rem;
  display: grid;
  gap: 0.5rem;
}

.obsidian-template--shared {
  background: rgba(240,249,255,0.95);
}

.obsidian-template__row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
}

.obsidian-template__apply {
  text-decoration: none;
  color: var(--surface-strong);
  display: flex;
  gap: 0.55rem;
  align-items: center;
}

.obsidian-template__icon {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: rgba(37,99,235,0.12);
  display: grid;
  place-items: center;
  font-size: 0.95rem;
  color: var(--surface-accent);
}

.obsidian-template__apply strong {
  display: block;
  font-size: 0.9rem;
}

.obsidian-template__apply em,
.obsidian-template__apply small {
  display: block;
  font-size: 0.72rem;
  color: var(--surface-muted);
  font-style: normal;
}

.obsidian-template__share {
  background: #fff;
  border: 1px solid rgba(148,163,184,0.45);
  border-radius: 999px;
  padding: 0.35rem 0.8rem;
  font-size: 0.75rem;
  cursor: pointer;
}

.obsidian-template__sharelist {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.obsidian-note-list {
  display: grid;
  gap: 0.75rem;
}

.obsidian-note {
  background: rgba(248,250,252,0.95);
  border-radius: 18px;
  border: 1px solid rgba(226,232,240,0.9);
  padding: 0.85rem;
  display: grid;
  gap: 0.55rem;
  cursor: pointer;
  transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.obsidian-note:hover {
  transform: translateY(-1px);
  border-color: rgba(37,99,235,0.32);
  box-shadow: 0 18px 32px rgba(15,23,42,0.1);
}

.obsidian-note.is-active {
  border-color: var(--surface-accent);
  box-shadow: 0 20px 38px rgba(37,99,235,0.18);
}

.obsidian-note__header {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  justify-content: space-between;
}

.obsidian-note__icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: rgba(37,99,235,0.12);
  display: grid;
  place-items: center;
  font-size: 1rem;
  color: var(--surface-accent);
}

.obsidian-note__titles h3 {
  margin: 0;
  font-size: 1rem;
  color: var(--surface-strong);
}

.obsidian-note__meta {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  flex-wrap: wrap;
  font-size: 0.74rem;
  color: var(--surface-muted);
}

.obsidian-note__timestamp {
  color: var(--surface-strong);
}

.obsidian-note__shared {
  color: #0f766e;
  font-weight: 600;
}

.obsidian-note__counts {
  display: flex;
  gap: 0.35rem;
}

.obsidian-note__pill {
  background: #fff;
  border-radius: 999px;
  padding: 0.22rem 0.55rem;
  font-size: 0.72rem;
  color: var(--surface-muted);
}

.obsidian-note__excerpt {
  margin: 0;
  font-size: 0.88rem;
  color: rgba(15,23,42,0.75);
}

.obsidian-note__tags {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.obsidian-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.22rem 0.55rem;
  border-radius: 999px;
  background: rgba(37,99,235,0.12);
  color: var(--surface-accent);
  font-size: 0.72rem;
}

.obsidian-tag::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--tag-color, var(--surface-accent));
}

.obsidian-preview {
  padding: 1.25rem;
}

.obsidian-preview__scroll {
  display: grid;
  gap: 0.95rem;
}

.obsidian-preview__header {
  display: flex;
  gap: 0.9rem;
  align-items: center;
}

.obsidian-preview__icon {
  width: 52px;
  height: 52px;
  border-radius: 16px;
  background: rgba(37,99,235,0.12);
  display: grid;
  place-items: center;
  font-size: 1.3rem;
  color: var(--surface-accent);
}

.obsidian-preview__header h2 {
  margin: 0.25rem 0 0;
  font-size: 1.35rem;
  color: var(--surface-strong);
}

.obsidian-preview__timestamp {
  margin: 0;
  color: var(--surface-muted);
  font-size: 0.78rem;
}

.obsidian-preview__actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.obsidian-preview__actions .btn,
.obsidian-btn,
.obsidian-primary {
  border-radius: 999px;
  padding: 0.5rem 1.05rem;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
  text-decoration: none;
  border: 1px solid transparent;
}

.obsidian-primary {
  background: var(--surface-accent);
  color: #fff;
  box-shadow: 0 18px 38px rgba(37,99,235,0.28);
}

.obsidian-btn {
  background: rgba(37,99,235,0.12);
  color: var(--surface-accent);
}

.obsidian-btn--ghost {
  background: #fff;
  border-color: rgba(148,163,184,0.45);
  color: var(--surface-strong);
}
.obsidian-preview__meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
  background: rgba(248,250,252,0.9);
  border-radius: 16px;
  border: 1px solid rgba(226,232,240,0.9);
  padding: 0.85rem;
  font-size: 0.8rem;
  color: var(--surface-strong);
}

.obsidian-preview__meta dt {
  margin: 0;
  font-size: 0.68rem;
  color: var(--surface-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.obsidian-preview__meta dd {
  margin: 0.2rem 0 0;
  font-weight: 600;
}

.obsidian-preview__properties {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 0.7rem;
  background: rgba(248,250,252,0.92);
  border-radius: 16px;
  border: 1px dashed rgba(148,163,184,0.45);
  padding: 0.85rem;
  font-size: 0.8rem;
}

.obsidian-preview__properties span {
  display: block;
  font-size: 0.68rem;
  color: var(--surface-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.obsidian-preview__properties strong {
  color: var(--surface-strong);
  margin-top: 0.25rem;
  display: block;
}

.obsidian-preview__tags {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.obsidian-preview__shares h3 {
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
  color: var(--surface-strong);
}

.obsidian-preview__sharelist {
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
  font-weight: 500;
}

.obsidian-pill.is-muted {
  background: rgba(148,163,184,0.18);
  color: var(--surface-muted);
}

.obsidian-empty {
  text-align: center;
  display: grid;
  gap: 0.6rem;
  justify-items: center;
  background: rgba(248,250,252,0.9);
  border: 1px dashed rgba(148,163,184,0.4);
  border-radius: 16px;
  padding: 1.5rem;
  color: var(--surface-muted);
}

.amanote-tag {
  background: rgba(37,99,235,0.12);
  color: var(--surface-accent);
  display: inline-flex;
  gap: 0.35rem;
  align-items: center;
  padding: 0.22rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
}

.amanote-tag::before {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--tag-color, var(--surface-accent));
}

.amanote-tag--empty {
  background: rgba(148,163,184,0.2);
  color: var(--surface-muted);
}

.badge {
  display: inline-flex;
  align-items: center;
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
}

.badge--blue { background: #dbeafe; color: #1d4ed8; }
.badge--indigo { background: #e0e7ff; color: #4338ca; }
.badge--purple { background: #ede9fe; color: #7c3aed; }
.badge--orange { background: #fef3c7; color: #b45309; }
.badge--green { background: #dcfce7; color: #047857; }
.badge--slate { background: #f1f5f9; color: #475569; }
.badge--danger { background: #fee2e2; color: #b91c1c; }
.badge--amber { background: #fef3c7; color: #92400e; }
.badge--teal { background: #ccfbf1; color: #0f766e; }

.obsidian-modal {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 2rem;
  background: rgba(15,23,42,0.28);
  backdrop-filter: blur(6px);
  transition: opacity 0.2s ease;
}

.obsidian-modal.hidden {
  opacity: 0;
  pointer-events: none;
}

.obsidian-modal__overlay {
  position: absolute;
  inset: 0;
}

.obsidian-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(560px, 100%);
  background: #fff;
  border-radius: 24px;
  border: 1px solid rgba(226,232,240,0.9);
  box-shadow: 0 45px 90px rgba(15,23,42,0.2);
  padding: 1.5rem;
  display: grid;
  gap: 1rem;
}

.obsidian-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.8rem;
}

.obsidian-modal__header h3 {
  margin: 0;
  font-size: 1.3rem;
}

.obsidian-modal__subtitle {
  margin: 0.25rem 0 0;
  color: var(--surface-muted);
  font-size: 0.85rem;
}

.obsidian-modal__close {
  background: rgba(226,232,240,0.7);
  border: none;
  border-radius: 50%;
  width: 34px;
  height: 34px;
  font-size: 1.2rem;
  cursor: pointer;
  color: var(--surface-strong);
}

.obsidian-modal__form {
  display: grid;
  gap: 0.9rem;
}

.obsidian-modal__body {
  display: grid;
  gap: 0.85rem;
}

.obsidian-modal__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.8rem;
}

.obsidian-field span {
  display: block;
  font-size: 0.75rem;
  color: var(--surface-muted);
  margin-bottom: 0.3rem;
}

.obsidian-field input,
.obsidian-field select,
.obsidian-field textarea {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(148,163,184,0.45);
  padding: 0.55rem 0.8rem;
  font-size: 0.95rem;
  color: var(--surface-strong);
}

.obsidian-modal__toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  color: var(--surface-strong);
}

.obsidian-modal__status {
  min-height: 1.2rem;
  font-size: 0.8rem;
  color: var(--surface-muted);
}

.obsidian-modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
}

.share-modal,
#templateShareModal {
  position: fixed;
  inset: 0;
  z-index: 75;
  display: grid;
  place-items: center;
  padding: 2rem;
  background: rgba(15,23,42,0.28);
  backdrop-filter: blur(6px);
  transition: opacity 0.2s ease;
}

.share-modal.hidden,
#templateShareModal.hidden {
  opacity: 0;
  pointer-events: none;
}

.share-modal__overlay,
#templateShareModal .share-modal__overlay {
  position: absolute;
  inset: 0;
}

.share-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(480px, 100%);
  background: #fff;
  border-radius: 22px;
  border: 1px solid rgba(226,232,240,0.9);
  box-shadow: 0 40px 90px rgba(15,23,42,0.18);
  padding: 1.3rem;
  display: grid;
  gap: 0.85rem;
}

.share-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.6rem;
}

.share-modal__header h3 {
  margin: 0;
  font-size: 1.25rem;
}

.share-modal__subtitle {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--surface-muted);
}

.share-modal__close {
  background: rgba(226,232,240,0.7);
  border: none;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  font-size: 1.15rem;
  cursor: pointer;
}

.share-modal__form {
  display: grid;
  gap: 0.8rem;
}

.share-modal__body {
  max-height: 260px;
  overflow: auto;
  border-radius: 12px;
  border: 1px solid rgba(226,232,240,0.85);
  padding: 0.6rem;
  display: grid;
  gap: 0.45rem;
}

.share-modal__option {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  background: rgba(248,250,252,0.95);
  border-radius: 10px;
  padding: 0.5rem 0.6rem;
  font-size: 0.85rem;
  color: var(--surface-strong);
}

.share-modal__status {
  font-size: 0.78rem;
  color: var(--surface-muted);
}

.share-modal__status.is-error {
  color: #b91c1c;
}

.share-modal__footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

@media (max-width: 1280px) {
  .amanote-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .amanote-rail {
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    grid-template-rows: none;
  }
  .obsidian-layout {
    grid-template-columns: minmax(0,1fr);
  }
  .obsidian-preview {
    order: -1;
  }
}

@media (max-width: 960px) {
  .obsidian-header {
    flex-direction: column;
    align-items: flex-start;
  }
  .obsidian-header__actions {
    width: 100%;
  }
  .obsidian-layout {
    gap: 1rem;
  }
}

@media (max-width: 720px) {
  .obsidian-shell {
    padding: 1.2rem;
  }
  .amanote-rail {
    padding: 1rem;
  }
  .obsidian-modal__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
/* Edit page extensions */
.obsidian-layout {
  grid-template-columns: 300px minmax(0, 1fr);
}

.obsidian-taglist {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
  min-height: 36px;
}

.obsidian-taginput {
  background: rgba(248,250,252,0.95);
  border: 1px dashed rgba(148,163,184,0.45);
  border-radius: 12px;
  padding: 0.45rem 0.65rem;
  color: var(--surface-strong);
}

.obsidian-tag-suggestions {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
  font-size: 0.75rem;
  color: var(--surface-muted);
}

.obsidian-attachments {
  display: grid;
  gap: 0.7rem;
}

.obsidian-attachment-card {
  background: var(--surface-panel);
  border: 1px solid rgba(226,232,240,0.9);
  border-radius: 16px;
  padding: 0.75rem;
  display: grid;
  gap: 0.6rem;
  box-shadow: 0 16px 34px rgba(15,23,42,0.08);
}

.obsidian-attachment-card__preview {
  border: 1px dashed rgba(148,163,184,0.45);
  border-radius: 12px;
  min-height: 120px;
  display: grid;
  place-items: center;
  color: var(--surface-muted);
  background: rgba(248,250,252,0.9);
  overflow: hidden;
}

.obsidian-attachment-card__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.obsidian-attachment-card__actions,
.obsidian-upload-form {
  display: flex;
  gap: 0.45rem;
  flex-wrap: wrap;
  align-items: center;
}

.obsidian-editor {
  background: var(--surface-panel);
  border-radius: 20px;
  border: 1px solid var(--surface-border);
  overflow: hidden;
  box-shadow: 0 24px 48px var(--surface-shadow);
  display: grid;
  gap: 0;
}

.obsidian-editor__cover {
  position: relative;
  height: 180px;
  background: linear-gradient(135deg, rgba(191,219,254,0.9), rgba(248,250,252,0.95));
  background-size: cover;
  background-position: center;
}

.obsidian-editor__cover.has-cover {
  background-size: cover;
}

.obsidian-editor__cover-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  padding: 0.9rem 1rem;
  background: linear-gradient(180deg, rgba(248,250,252,0.05), rgba(15,23,42,0.18));
  gap: 0.7rem;
}

.obsidian-editor__head {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
  padding: 1.1rem 1.2rem 0;
}

.obsidian-editor__icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  background: rgba(37,99,235,0.14);
  display: grid;
  place-items: center;
  font-size: 1.35rem;
  color: var(--surface-accent);
}

.obsidian-editor__titlegroup {
  display: grid;
  gap: 0.55rem;
  flex: 1;
}

.obsidian-editor__title {
  background: transparent;
  border: none;
  border-bottom: 1px solid rgba(191,219,254,0.8);
  padding: 0.3rem 0;
  font-size: 1.6rem;
  font-weight: 600;
  color: var(--surface-strong);
}

.obsidian-editor__title:focus {
  outline: none;
  border-color: var(--surface-accent);
}

.obsidian-editor__blocks {
  padding: 1.2rem;
  display: grid;
  gap: 1rem;
}

.obsidian-editor__blocks-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.8rem;
  flex-wrap: wrap;
}

.obsidian-editor__toolbar {
  display: flex;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.chip {
  background: rgba(37,99,235,0.12);
  border: 1px solid rgba(191,219,254,0.9);
  color: var(--surface-accent);
  border-radius: 999px;
  padding: 0.25rem 0.65rem;
  font-size: 0.8rem;
  cursor: pointer;
  transition: background 0.2s ease, border-color 0.2s ease;
}

.chip:hover {
  background: rgba(191,219,254,0.6);
  border-color: rgba(37,99,235,0.45);
}

.obsidian-editor__blocks-list {
  display: grid;
  gap: 0.9rem;
}

.composer-block {
  background: rgba(248,250,252,0.95);
  border: 1px solid rgba(226,232,240,0.9);
  border-radius: 16px;
  padding: 0.9rem;
  display: grid;
  gap: 0.7rem;
}

.composer-block__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.composer-block__type {
  background: rgba(37,99,235,0.12);
  border: 1px solid rgba(191,219,254,0.9);
  color: var(--surface-accent);
  border-radius: 999px;
  padding: 0.3rem 0.7rem;
  font-size: 0.76rem;
}

.composer-block__actions {
  display: flex;
  gap: 0.35rem;
}

.composer-block__btn {
  background: rgba(226,232,240,0.9);
  border: 1px solid rgba(203,213,225,0.9);
  color: var(--surface-strong);
  border-radius: 0.5rem;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
  font-size: 0.78rem;
}

.composer-block__btn--danger {
  background: rgba(254,228,226,0.9);
  border-color: rgba(254,202,202,0.9);
  color: #b91c1c;
}

.composer-block__btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.composer-block__text {
  background: #fff;
  border: 1px solid rgba(191,219,254,0.8);
  border-radius: 12px;
  padding: 0.7rem 0.8rem;
  color: var(--surface-strong);
  min-height: 120px;
  resize: vertical;
  font-size: 0.95rem;
}

.composer-block__checkbox {
  display: flex;
  gap: 0.45rem;
  align-items: flex-start;
  color: var(--surface-strong);
}

.composer-block__icon-input {
  display: grid;
  gap: 0.35rem;
  color: var(--surface-muted);
}

.composer-block__icon-input input {
  background: #fff;
  border: 1px solid rgba(191,219,254,0.8);
  border-radius: 0.65rem;
  padding: 0.45rem 0.6rem;
  color: var(--surface-strong);
}

.composer-block__hint {
  margin: 0;
  font-size: 0.74rem;
  color: var(--surface-muted);
}

.composer-block__divider {
  height: 2px;
  background: linear-gradient(90deg, rgba(191,219,254,0.7), rgba(226,232,240,0.8));
  border-radius: 999px;
}

.obsidian-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.obsidian-template-browser {
  display: grid;
  gap: 0.6rem;
}

.obsidian-template-card {
  background: var(--surface-panel);
  border: 1px solid rgba(226,232,240,0.9);
  border-radius: 16px;
  padding: 0.7rem;
  display: grid;
  gap: 0.5rem;
}

.obsidian-template-card__body {
  display: flex;
  gap: 0.6rem;
  align-items: center;
}

.obsidian-template-card__icon {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  background: rgba(37,99,235,0.12);
  display: grid;
  place-items: center;
  font-size: 1rem;
  color: var(--surface-accent);
}

.obsidian-template-card__shares {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.obsidian-panel--danger {
  border: 1px solid rgba(254,202,202,0.9);
  background: rgba(254,242,242,0.95);
}

.obsidian-panel--danger button {
  color: #b91c1c;
}

.flash {
  border-radius: 12px;
  padding: 0.85rem 1rem;
  margin-bottom: 1rem;
  font-weight: 500;
}

.flash-error {
  background: rgba(254,242,242,0.95);
  border: 1px solid rgba(254,202,202,0.9);
  color: #b91c1c;
}

@media (max-width: 960px) {
  .obsidian-editor__head {
    flex-direction: column;
    align-items: flex-start;
  }
}
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
