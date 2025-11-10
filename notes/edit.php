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
$templates       = notes_fetch_templates_for_user($meId);
$ownedTemplates  = array_values(array_filter($templates, static fn($tpl) => !empty($tpl['is_owner'])));
foreach ($ownedTemplates as &$tpl) {
    $details = notes_template_share_details((int)($tpl['id'] ?? 0));
    $tpl['share_details'] = $details;
    $tpl['share_ids']     = array_map(static fn($share) => (int)($share['id'] ?? 0), $details);
    $tpl['share_labels']  = array_map(static fn($share) => (string)($share['label'] ?? ''), $details);
}
unset($tpl);

$errors = [];
$csrfToken = csrf_token();

if (is_post()) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
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

        if ($canShare && isset($_POST['update_template_shares'])) {
            $templateId = (int)($_POST['template_id'] ?? 0);
            $selected   = array_map('intval', (array)($_POST['shared_ids'] ?? []));
            $template   = $templateId > 0 ? notes_template_fetch($templateId) : null;

            if (!$template || !notes_template_can_share($template, $meId)) {
                if ($isAjax) {
                    header('Content-Type: application/json', true, 403);
                    echo json_encode([
                        'ok'      => false,
                        'message' => 'You cannot update this template.',
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $errors[] = 'You cannot update this template.';
            } else {
                try {
                    notes_apply_template_shares($templateId, $selected, $template, true);
                    $shareDetails = notes_template_share_details($templateId);
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'ok'          => true,
                            'template_id' => $templateId,
                            'shares'      => $shareDetails,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    redirect_with_message('edit.php?id=' . $id . '#templates', 'Template sharing updated.', 'success');
                } catch (Throwable $e) {
                    error_log('notes_update_template_shares failed: ' . $e->getMessage());
                    if ($isAjax) {
                        header('Content-Type: application/json', true, 500);
                        echo json_encode([
                            'ok'      => false,
                            'message' => 'Failed to update template.',
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $errors[] = 'Failed to update template.';
                }
            }
        }

        if ($canShare && (isset($_POST['save_shares']) || isset($_POST['update_shares']))) {
            $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));

            try {
                $result = notes_apply_shares($id, $selected, $note, true);
                $currentShares = $result['after'];
                $shareDetails = notes_get_share_details($id);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok'     => true,
                        'shares' => $shareDetails,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                redirect_with_message('edit.php?id=' . $id, 'Shares updated.', 'success');
            } catch (Throwable $e) {
                error_log('notes_update_shares failed: ' . $e->getMessage());
                if ($isAjax) {
                    header('Content-Type: application/json', true, 500);
                    echo json_encode([
                        'ok'      => false,
                        'message' => 'Failed to update shares.',
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
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
$formTitle        = $_POST['title'] ?? ($note['title'] ?? '');
$noteDateValue    = $_POST['note_date'] ?? ($note['note_date'] ?? date('Y-m-d'));
$iconValue        = $_POST['icon'] ?? ($meta['icon'] ?? '');
$coverValue       = $_POST['cover_url'] ?? ($meta['cover_url'] ?? '');
$statusValue      = $_POST['status'] ?? ($meta['status'] ?? NOTES_DEFAULT_STATUS);
$propertyProject  = $_POST['property_project'] ?? ($meta['properties']['project'] ?? '');
$propertyLocation = $_POST['property_location'] ?? ($meta['properties']['location'] ?? '');
$propertyDueDate  = $_POST['property_due_date'] ?? ($meta['properties']['due_date'] ?? '');
$propertyPriority = $_POST['property_priority'] ?? ($meta['properties']['priority'] ?? 'Medium');
$bodyFallback     = $_POST['body'] ?? ($note['body'] ?? '');
$configAttr       = htmlspecialchars(json_encode($composerConfig, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$shareConfigAttr = $canShare
    ? htmlspecialchars(json_encode([
        'selected' => array_values($currentShares),
        'owner'    => $ownerId,
      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')
    : '';
?>
<section class="obsidian-shell obsidian-shell--composer" data-theme="obsidian" data-note-page data-note-id="<?= (int)$id; ?>" data-csrf="<?= sanitize($csrfToken); ?>" data-can-share="<?= $canShare ? '1' : '0'; ?>" data-share-config="<?= $shareConfigAttr; ?>">
  <header class="obsidian-header">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Edit note</span>
      <h1><?= sanitize($note['title'] ?: 'Untitled'); ?></h1>
      <p class="obsidian-header__subtitle">Keep content, properties, and collaborators aligned inside a dark vault workspace.</p>
    </div>
    <div class="obsidian-header__actions">
      <a class="btn obsidian-btn--ghost" href="view.php?id=<?= (int)$note['id']; ?>">View note</a>
      <?php if ($canShare): ?>
        <button class="btn obsidian-btn" type="button" data-share-open>Share</button>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <button class="btn obsidian-primary" type="submit" form="editNoteForm">Save changes</button>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?= sanitize(implode(' ', $errors)); ?></div>
  <?php endif; ?>

  <form method="post" class="obsidian-form" id="editNoteForm" novalidate>
    <div class="obsidian-layout obsidian-layout--composer">
      <aside class="obsidian-sidebar obsidian-sidebar--composer">
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
            <p>Manage up to three reference images for this note.</p>
          </header>
          <div class="obsidian-attachments obsidian-attachments--edit">
            <?php for ($i = 1; $i <= 3; $i++): $photo = $photos[$i] ?? null; ?>
              <div class="obsidian-attachment-card">
                <div class="obsidian-attachment-card__preview">
                  <?php if ($photo): ?>
                    <img src="<?= sanitize($photo['url']); ?>" alt="Attachment <?= $i; ?>" loading="lazy">
                  <?php else: ?>
                    <span class="obsidian-muted">Slot <?= $i; ?></span>
                  <?php endif; ?>
                </div>
                <div class="obsidian-attachment-card__actions">
                  <?php if ($photo): ?>
                    <a class="btn obsidian-btn" href="<?= sanitize($photo['url']); ?>" target="_blank" rel="noopener">Open</a>
                    <?php if ($canEdit): ?>
                      <form method="post" onsubmit="return confirm('Remove this attachment?');">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                        <button class="btn obsidian-btn--ghost" type="submit" name="delete_photo_id" value="<?= (int)$photo['id']; ?>">Remove</button>
                      </form>
                    <?php endif; ?>
                  <?php elseif ($canEdit): ?>
                    <form method="post" enctype="multipart/form-data" class="obsidian-upload-form">
                      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
                      <input type="hidden" name="upload_position" value="<?= $i; ?>">
                      <label class="btn obsidian-btn">
                        Choose
                        <input type="file" name="photo" accept="image/*,image/heic,image/heif" class="visually-hidden" required>
                      </label>
                      <button class="btn obsidian-primary" type="submit">Upload</button>
                    </form>
                  <?php else: ?>
                    <span class="obsidian-muted">No attachment.</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </section>

        <section class="obsidian-panel obsidian-panel--shares">
          <header class="obsidian-panel__header">
            <h2>Access</h2>
          </header>
          <div class="obsidian-detail__shares" data-share-summary data-empty-text="Private">
            <?php if ($shareDetails): ?>
              <?php foreach ($shareDetails as $share): ?>
                <span class="obsidian-pill"><?= sanitize($share['label']); ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="obsidian-muted" data-share-empty>Private</span>
            <?php endif; ?>
          </div>
          <?php if ($canShare): ?>
          <button class="btn obsidian-btn" type="button" data-share-open>Manage collaborators</button>
          <?php endif; ?>
        </section>

        <?php if ($canEdit): ?>
        <section class="obsidian-panel obsidian-panel--template">
          <header class="obsidian-panel__header">
            <h2>Template</h2>
            <p class="obsidian-panel__hint">Capture this structure for future notes.</p>
          </header>
          <label class="obsidian-field">
            <span>Template name</span>
            <input type="text" name="template_name" maxlength="200" value="<?= sanitize($_POST['template_name'] ?? ($note['title'] ?? '')); ?>" required>
          </label>
          <div class="obsidian-form__actions">
            <button class="btn obsidian-btn" type="submit" name="save_template" value="1">Save as template</button>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($ownedTemplates): ?>
        <section class="obsidian-panel obsidian-panel--templates">
          <header class="obsidian-panel__header">
            <h2>My templates</h2>
            <p class="obsidian-panel__hint">Share reusable layouts with your team.</p>
          </header>
          <div class="obsidian-template-browser">
            <?php foreach ($ownedTemplates as $tpl):
              $tplId = (int)($tpl['id'] ?? 0);
              $tplName = trim((string)($tpl['name'] ?? 'Untitled'));
              $tplIcon = trim((string)($tpl['icon'] ?? '')) ?: '📄';
              $tplPayload = htmlspecialchars(json_encode([
                'id'         => $tplId,
                'name'       => $tplName,
                'shareIds'   => $tpl['share_ids'] ?? [],
                'shareLabels'=> $tpl['share_labels'] ?? [],
                'ownerId'    => (int)($tpl['owner_id'] ?? $meId),
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
            ?>
            <article class="obsidian-template-card" data-template-id="<?= $tplId; ?>">
              <div class="obsidian-template-card__body">
                <span class="obsidian-template-card__icon"><?= sanitize($tplIcon); ?></span>
                <div>
                  <strong><?= sanitize($tplName); ?></strong>
                  <?php if (!empty($tpl['title'])): ?><em><?= sanitize($tpl['title']); ?></em><?php endif; ?>
                </div>
              </div>
              <div class="obsidian-template-card__shares" data-template-share-list="<?= $tplId; ?>">
                <?php if (!empty($tpl['share_labels'])): ?>
                  <?php foreach ($tpl['share_labels'] as $label): ?>
                    <span class="obsidian-pill"><?= sanitize($label); ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="obsidian-pill is-muted">Private</span>
                <?php endif; ?>
              </div>
              <button type="button" class="btn obsidian-btn--ghost" data-template-share-button="<?= $tplId; ?>" data-template-share="<?= $tplPayload; ?>">Share template</button>
            </article>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <form method="post" class="obsidian-panel obsidian-panel--danger" onsubmit="return confirm('Delete this note? This cannot be undone.');">
          <header class="obsidian-panel__header">
            <h2>Danger zone</h2>
          </header>
          <p class="obsidian-muted">Deleting removes the note, blocks, comments, and attachments.</p>
          <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
          <button class="btn obsidian-btn--ghost" type="submit" name="delete_note" value="1">Delete note</button>
        </form>
        <?php endif; ?>
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
          <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
          <button class="btn obsidian-primary" type="submit" name="save_note" value="1">Save changes</button>
          <a class="btn obsidian-btn--ghost" href="view.php?id=<?= (int)$note['id']; ?>">Cancel</a>
        </div>
      </div>
    </div>
  </form>
</section>

<?php if ($canShare): ?>
<div class="obsidian-modal hidden" id="noteShareModal" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog obsidian-modal__dialog--share" role="dialog" aria-modal="true" aria-labelledby="editShareTitle">
    <header class="obsidian-modal__header">
      <div>
        <h3 id="editShareTitle">Share this note</h3>
        <p class="obsidian-modal__subtitle">Update collaborators without leaving the editor.</p>
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
              $checked = in_array($uid, $currentShares, true);
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
        <button class="btn obsidian-primary" type="submit" name="save_shares" value="1">Save access</button>
      </footer>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($ownedTemplates): ?>
<div class="obsidian-modal hidden" id="templateShareModal" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog obsidian-modal__dialog--share" role="dialog" aria-modal="true">
    <header class="obsidian-modal__header">
      <div>
        <h3>Share template</h3>
        <p class="obsidian-modal__subtitle" data-share-template-title></p>
      </div>
      <button type="button" class="obsidian-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="obsidian-modal__form" data-template-share-form>
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
      <input type="hidden" name="update_template_shares" value="1">
      <input type="hidden" name="template_id" value="">
      <div class="obsidian-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <?php $uid = (int)($option['id'] ?? 0); $label = trim((string)($option['email'] ?? ($option['label'] ?? 'User #' . $uid))); ?>
          <label class="obsidian-modal__option" data-template-share-option data-user-id="<?= $uid; ?>" data-label="<?= sanitize($label); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?= $uid; ?>">
            <span><?= sanitize($label); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <footer class="obsidian-modal__footer">
        <span class="obsidian-modal__status" data-template-share-status></span>
        <button type="submit" class="btn obsidian-primary">Save access</button>
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
  const composer = document.querySelector('[data-note-composer]');
  const templateShareModal = document.getElementById('templateShareModal');
  const templateShareForm = templateShareModal ? templateShareModal.querySelector('[data-template-share-form]') : null;
  const templateShareStatus = templateShareModal ? templateShareModal.querySelector('[data-template-share-status]') : null;
  const templateShareTitle = templateShareModal ? templateShareModal.querySelector('[data-share-template-title]') : null;
  const templateOptions = templateShareModal ? Array.from(templateShareModal.querySelectorAll('[data-template-share-option]')) : [];
  const templateState = new Map();

  const templateIdInput = templateShareForm ? templateShareForm.querySelector('input[name="template_id"]') : null;

  function applyTemplateState(templateId, payload) {
    if (!templateId) return {};
    const key = String(templateId);
    const existing = templateState.get(key) || {};
    const next = Object.assign({ id: Number(templateId) }, existing, payload || {});
    templateState.set(key, next);
    const trigger = document.querySelector(`[data-template-share-button="${key}"]`);
    if (trigger) {
      try {
        trigger.setAttribute('data-template-share', JSON.stringify(next));
      } catch (err) {
        // ignore JSON issues
      }
    }
    return next;
  }

  function renderTemplateShares(templateId, entries) {
    const list = document.querySelector(`[data-template-share-list="${templateId}"]`);
    if (!list) return;
    list.innerHTML = '';
    if (!entries || !entries.length) {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill is-muted';
      pill.textContent = 'Private';
      list.appendChild(pill);
      return;
    }
    entries.forEach((entry) => {
      const pill = document.createElement('span');
      pill.className = 'obsidian-pill';
      pill.textContent = entry.label || entry;
      list.appendChild(pill);
    });
  }

  function syncTemplateOptions(state) {
    const selected = Array.isArray(state.shareIds) ? state.shareIds.map(Number) : [];
    const ownerId = Number(state.ownerId || 0);
    templateOptions.forEach((option) => {
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      const uid = Number(option.dataset.userId || '0');
      const isOwner = ownerId > 0 && uid === ownerId;
      checkbox.checked = isOwner || selected.includes(uid);
      checkbox.disabled = isOwner;
      option.classList.toggle('is-disabled', isOwner);
    });
  }

  if (templateShareModal) {
    templateShareModal.querySelectorAll('[data-modal-close]').forEach((btn) => {
      btn.addEventListener('click', () => {
        templateShareModal.classList.add('hidden');
        templateShareModal.setAttribute('aria-hidden', 'true');
      });
    });

    document.querySelectorAll('[data-template-share]').forEach((button) => {
      const raw = button.getAttribute('data-template-share') || '{}';
      let parsed = {};
      try {
        parsed = JSON.parse(raw);
      } catch (err) {
        console.warn('Template payload parse error', err);
      }
      const templateId = button.getAttribute('data-template-share-button') || String(parsed.id || '');
      if (templateId) {
        applyTemplateState(templateId, parsed);
      }
      button.addEventListener('click', (event) => {
        event.preventDefault();
        if (!templateShareModal || !templateShareForm || !templateIdInput) return;
        const key = String(templateId || '');
        const state = templateState.get(key) || applyTemplateState(key, parsed);
        templateIdInput.value = key;
        if (templateShareTitle) templateShareTitle.textContent = state.name || 'Template';
        syncTemplateOptions(state);
        if (templateShareStatus) {
          templateShareStatus.textContent = '';
          templateShareStatus.classList.remove('is-error');
        }
        templateShareModal.classList.remove('hidden');
        templateShareModal.setAttribute('aria-hidden', 'false');
        const first = templateShareModal.querySelector('input[type="checkbox"]');
        if (first) first.focus();
      });
    });

    if (templateShareForm) {
      templateShareForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(templateShareForm);
        fetch('edit.php?id=<?= (int)$id; ?>', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then((res) => {
          if (!res.ok) throw new Error('Request failed');
          return res.json();
        }).then((data) => {
          if (data && data.ok) {
            const templateId = String(data.template_id || formData.get('template_id') || '');
            const shares = Array.isArray(data.shares) ? data.shares : [];
            const normalized = shares.map((entry) => ({
              id: Number(entry.id || entry),
              label: entry.label || String(entry.id || ''),
            })).filter((entry) => entry.label !== '' || entry.id > 0);
            const shareIds = normalized.map((entry) => entry.id).filter((id) => id > 0);
            const shareLabels = normalized.map((entry) => entry.label);
            const state = applyTemplateState(templateId, {
              shareIds,
              shareLabels,
            });
            renderTemplateShares(templateId, normalized);
            syncTemplateOptions(state);
            if (templateShareStatus) {
              templateShareStatus.textContent = 'Template sharing updated';
              templateShareStatus.classList.remove('is-error');
            }
            setTimeout(() => {
              templateShareModal.classList.add('hidden');
              templateShareModal.setAttribute('aria-hidden', 'true');
            }, 300);
          } else {
            throw new Error('Bad response');
          }
        }).catch(() => {
          if (templateShareStatus) {
            templateShareStatus.textContent = 'Unable to update template.';
            templateShareStatus.classList.add('is-error');
          }
        });
      });
    }
  }

  const noteShareModal = document.getElementById('noteShareModal');
  const noteShareForm = document.getElementById('noteShareForm');
  const noteShareStatus = document.getElementById('noteShareStatus');
  const noteShareSearch = document.getElementById('noteShareSearch');
  const noteShareOptions = noteShareModal ? Array.from(noteShareModal.querySelectorAll('[data-share-option]')) : [];
  const shareSummary = document.querySelector('[data-share-summary]');
  const shareList = document.querySelector('[data-share-list]');
  const shareEmptyText = shareSummary ? (shareSummary.getAttribute('data-empty-text') || 'Private') : 'Private';
  const shareTrigger = document.querySelector('[data-share-open]');
  const noteId = document.querySelector('[data-note-page]')?.getAttribute('data-note-id') || '';
  const csrfField = '<?= CSRF_TOKEN_NAME; ?>';
  const csrfToken = document.querySelector('[data-note-page]')?.getAttribute('data-csrf') || '';

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

  function syncNoteShareOptions(selected) {
    noteShareOptions.forEach((option) => {
      const checkbox = option.querySelector('input');
      if (!checkbox) return;
      const uid = Number(checkbox.value || '0');
      checkbox.checked = selected.includes(uid);
      if (checkbox.disabled) {
        checkbox.checked = true;
      }
    });
  }

  if (noteShareModal) {
    noteShareModal.querySelectorAll('[data-modal-close]').forEach((btn) => {
      btn.addEventListener('click', () => {
        noteShareModal.classList.add('hidden');
        noteShareModal.setAttribute('aria-hidden', 'true');
      });
    });
  }

  if (shareTrigger && noteShareModal) {
    shareTrigger.addEventListener('click', (event) => {
      event.preventDefault();
      noteShareModal.classList.remove('hidden');
      noteShareModal.setAttribute('aria-hidden', 'false');
      const first = noteShareModal.querySelector('input[type="checkbox"]');
      if (first) first.focus();
    });
  }

  if (noteShareSearch && noteShareOptions.length) {
    noteShareSearch.addEventListener('input', () => {
      const query = noteShareSearch.value.toLowerCase();
      let matches = 0;
      noteShareOptions.forEach((option) => {
        const label = option.dataset.label || '';
        const match = label.includes(query);
        option.style.display = match ? '' : 'none';
        if (match) matches += 1;
      });
      const empty = document.getElementById('noteShareEmpty');
      if (empty) empty.hidden = matches !== 0;
    });
  }

  if (noteShareForm) {
    noteShareForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(noteShareForm);
      fetch(`edit.php?id=${noteId}`, {
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
          syncNoteShareOptions(ids);
          renderShareBadges(shareList, shares);
          renderShareBadges(shareSummary, shares);
          if (noteShareStatus) {
            noteShareStatus.textContent = 'Access updated';
            noteShareStatus.classList.remove('is-error');
          }
          setTimeout(() => {
            noteShareModal.classList.add('hidden');
            noteShareModal.setAttribute('aria-hidden', 'true');
          }, 300);
        } else {
          throw new Error('Bad response');
        }
      }).catch(() => {
        if (noteShareStatus) {
          noteShareStatus.textContent = 'Could not update shares.';
          noteShareStatus.classList.add('is-error');
        }
      });
    });
  }
});
</script>
