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
<section class="obsidian-shell obsidian-shell--composer" data-theme="obsidian" data-note-page data-note-id="<?php echo  (int)$id; ?>" data-csrf="<?php echo  sanitize($csrfToken); ?>" data-can-share="<?php echo  $canShare ? '1' : '0'; ?>" data-share-config="<?php echo  $shareConfigAttr; ?>">
  <header class="obsidian-header">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Edit note</span>
      <h1><?php echo  sanitize($note['title'] ?: 'Untitled'); ?></h1>
      <p class="obsidian-header__subtitle">Keep content, properties, and collaborators aligned inside a dark vault workspace.</p>
    </div>
    <div class="obsidian-header__actions">
      <a class="btn obsidian-btn--ghost" href="view.php?id=<?php echo  (int)$note['id']; ?>">View note</a>
      <?php if ($canShare): ?>
        <button class="btn obsidian-btn" type="button" data-share-open>Share</button>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <button class="btn obsidian-primary" type="submit" form="editNoteForm">Save changes</button>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($errors): ?>
    <div class="flash flash-error"><?php echo  sanitize(implode(' ', $errors)); ?></div>
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
            <p>Manage up to three reference images for this note.</p>
          </header>
          <div class="obsidian-attachments obsidian-attachments--edit">
            <?php for ($i = 1; $i <= 3; $i++): $photo = $photos[$i] ?? null; ?>
              <div class="obsidian-attachment-card">
                <div class="obsidian-attachment-card__preview">
                  <?php if ($photo): ?>
                    <img src="<?php echo  sanitize($photo['url']); ?>" alt="Attachment <?php echo  $i; ?>" loading="lazy">
                  <?php else: ?>
                    <span class="obsidian-muted">Slot <?php echo  $i; ?></span>
                  <?php endif; ?>
                </div>
                <div class="obsidian-attachment-card__actions">
                  <?php if ($photo): ?>
                    <a class="btn obsidian-btn" href="<?php echo  sanitize($photo['url']); ?>" target="_blank" rel="noopener">Open</a>
                    <?php if ($canEdit): ?>
                      <form method="post" onsubmit="return confirm('Remove this attachment?');">
                        <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
                        <button class="btn obsidian-btn--ghost" type="submit" name="delete_photo_id" value="<?php echo  (int)$photo['id']; ?>">Remove</button>
                      </form>
                    <?php endif; ?>
                  <?php elseif ($canEdit): ?>
                    <form method="post" enctype="multipart/form-data" class="obsidian-upload-form">
                      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
                      <input type="hidden" name="upload_position" value="<?php echo  $i; ?>">
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
                <span class="obsidian-pill"><?php echo  sanitize($share['label']); ?></span>
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
            <input type="text" name="template_name" maxlength="200" value="<?php echo  sanitize($_POST['template_name'] ?? ($note['title'] ?? '')); ?>" required>
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
            <article class="obsidian-template-card" data-template-id="<?php echo  $tplId; ?>">
              <div class="obsidian-template-card__body">
                <span class="obsidian-template-card__icon"><?php echo  sanitize($tplIcon); ?></span>
                <div>
                  <strong><?php echo  sanitize($tplName); ?></strong>
                  <?php if (!empty($tpl['title'])): ?><em><?php echo  sanitize($tpl['title']); ?></em><?php endif; ?>
                </div>
              </div>
              <div class="obsidian-template-card__shares" data-template-share-list="<?php echo  $tplId; ?>">
                <?php if (!empty($tpl['share_labels'])): ?>
                  <?php foreach ($tpl['share_labels'] as $label): ?>
                    <span class="obsidian-pill"><?php echo  sanitize($label); ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="obsidian-pill is-muted">Private</span>
                <?php endif; ?>
              </div>
              <button type="button" class="btn obsidian-btn--ghost" data-template-share-button="<?php echo  $tplId; ?>" data-template-share="<?php echo  $tplPayload; ?>">Share template</button>
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
          <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
          <button class="btn obsidian-btn--ghost" type="submit" name="delete_note" value="1">Delete note</button>
        </form>
        <?php endif; ?>
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
          <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
          <button class="btn obsidian-primary" type="submit" name="save_note" value="1">Save changes</button>
          <a class="btn obsidian-btn--ghost" href="view.php?id=<?php echo  (int)$note['id']; ?>">Cancel</a>
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
              $checked = in_array($uid, $currentShares, true);
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
      <input type="hidden" name="<?php echo  CSRF_TOKEN_NAME; ?>" value="<?php echo  sanitize($csrfToken); ?>">
      <input type="hidden" name="update_template_shares" value="1">
      <input type="hidden" name="template_id" value="">
      <div class="obsidian-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <?php $uid = (int)($option['id'] ?? 0); $label = trim((string)($option['email'] ?? ($option['label'] ?? 'User #' . $uid))); ?>
          <label class="obsidian-modal__option" data-template-share-option data-user-id="<?php echo  $uid; ?>" data-label="<?php echo  sanitize($label); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?php echo  $uid; ?>">
            <span><?php echo  sanitize($label); ?></span>
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
        fetch('edit.php?id=<?php echo  (int)$id; ?>', {
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
  const csrfField = '<?php echo  CSRF_TOKEN_NAME; ?>';
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
