<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$meId = (int)(current_user()['id'] ?? 0);
$pdo  = get_pdo();

if (is_post()) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $errorMessage = '';
    $successPayload = null;

    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errorMessage = 'Invalid security token.';
    } elseif (isset($_POST['update_note_shares'])) {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $selected = array_map('intval', (array)($_POST['shared_ids'] ?? []));
        $note = notes_fetch($noteId);
        if (!$note || !notes_can_share($note)) {
            $errorMessage = 'You cannot change sharing for this note.';
        } else {
            try {
                $result = notes_apply_shares($noteId, $selected, $note, true);
                $shares = notes_get_share_details($noteId);
                $successPayload = [
                    'ok'       => true,
                    'shares'   => $shares,
                    'selected' => $result['after'],
                    'note_id'  => $noteId,
                ];
            } catch (Throwable $e) {
                error_log('index note share failed: ' . $e->getMessage());
                $errorMessage = 'Failed to update shares.';
            }
        }
    } elseif (isset($_POST['update_template_shares'])) {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $selected   = array_map('intval', (array)($_POST['shared_ids'] ?? []));
        $template   = notes_template_fetch($templateId);
        if (!$template || !notes_template_can_share($template, $meId)) {
            $errorMessage = 'You cannot share this template.';
        } else {
            try {
                $result = notes_apply_template_shares($templateId, $selected, $template, true);
                $shares = notes_template_share_details($templateId);
                $successPayload = [
                    'ok'         => true,
                    'shares'     => $shares,
                    'selected'   => $result['after'],
                    'template_id'=> $templateId,
                ];
            } catch (Throwable $e) {
                error_log('index template share failed: ' . $e->getMessage());
                $errorMessage = 'Failed to update template shares.';
            }
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        if ($errorMessage !== '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $errorMessage], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($successPayload ?? ['ok' => true], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($errorMessage !== '') {
        redirect_with_message('index.php', $errorMessage, 'error');
    } else {
        redirect_with_message('index.php', 'Sharing updated.', 'success');
    }
    exit;
}

if (!function_exists('notes__search_index')) {
    function notes__search_index(string $title, string $body = ''): string {
        $plain = trim($title . ' ' . preg_replace('/\s+/u', ' ', strip_tags($body)));
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($plain, 'UTF-8');
        }
        return strtolower($plain);
    }
}
if (!function_exists('notes__relative_time')) {
    function notes__relative_time(?string $timestamp): string {
        if (!$timestamp) {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($timestamp);
        } catch (Throwable $e) {
            return (string)$timestamp;
        }
        $now  = new DateTimeImmutable('now');
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        $mins = (int)floor($diff / 60);
        if ($mins < 60) {
            return $mins . 'm ago';
        }
        $hours = (int)floor($mins / 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }
        $days = (int)floor($hours / 24);
        if ($days < 7) {
            return $days . 'd ago';
        }
        if ($days < 30) {
            return (int)floor($days / 7) . 'w ago';
        }
        return $dt->format('M j, Y');
    }
}
if (!function_exists('notes__excerpt')) {
    function notes__excerpt(string $body, int $limit = 180): string {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
        if ($plain === '') {
            return '';
        }
        if (function_exists('mb_strimwidth')) {
            return (string)mb_strimwidth($plain, 0, $limit, '…', 'UTF-8');
        }
        return strlen($plain) > $limit ? substr($plain, 0, $limit - 1) . '…' : $plain;
    }
}
if (!function_exists('notes__format_date')) {
    function notes__format_date(?string $date): string {
        $date = trim((string)$date);
        if ($date === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($date))->format('M j, Y');
        } catch (Throwable $e) {
            return $date;
        }
    }
}

$hasNoteDate    = notes__col_exists($pdo, 'notes', 'note_date');
$hasCreatedAt   = notes__col_exists($pdo, 'notes', 'created_at');
$hasUpdatedAt   = notes__col_exists($pdo, 'notes', 'updated_at');
$hasPhotosTbl   = notes__table_exists($pdo, 'note_photos');
$hasCommentsTbl = notes__table_exists($pdo, 'note_comments');
$hasMetaTbl     = notes__table_exists($pdo, 'note_pages_meta');
$hasTagTbl      = notes__table_exists($pdo, 'note_tag_assignments') && notes__table_exists($pdo, 'note_tags_catalog');

$search      = trim((string)($_GET['q'] ?? ''));
$from        = trim((string)($_GET['from'] ?? ''));
$to          = trim((string)($_GET['to'] ?? ''));
$statusParam = trim((string)($_GET['status'] ?? ''));
$tagFilter   = trim((string)($_GET['tag'] ?? ''));

$statuses = notes_available_statuses();
$statusFilter = '';
if ($statusParam !== '') {
    $candidateKey = strtolower(str_replace([' ', '-'], '_', $statusParam));
    if (isset($statuses[$candidateKey])) {
        $statusFilter = $candidateKey;
    } else {
        foreach ($statuses as $slug => $label) {
            if (strcasecmp($label, $statusParam) === 0) {
                $statusFilter = $slug;
                break;
            }
        }
    }
}

$allowedViews = ['table', 'board'];
$viewInput = $_GET['view'] ?? '';
if ($viewInput === 'sticky') {
    $viewInput = 'board';
}
if ($viewInput && in_array($viewInput, $allowedViews, true)) {
    $view = $viewInput;
} else {
    $cookieView = $_COOKIE['notes_view'] ?? '';
    if ($cookieView === 'sticky') {
        $cookieView = 'board';
    }
    if ($cookieView && in_array($cookieView, $allowedViews, true)) {
        $view = $cookieView;
    } else {
        $view = 'table';
    }
}
@setcookie('notes_view', $view, time() + 31536000, '/', '', false, true);

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(n.title LIKE :q OR COALESCE(n.body, "") LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($hasNoteDate && $from !== '') {
    $where[] = 'n.note_date >= :from';
    $params[':from'] = $from;
}
if ($hasNoteDate && $to !== '') {
    $where[] = 'n.note_date <= :to';
    $params[':to'] = $to;
}

$sharesCol = notes__shares_column($pdo);
if ($sharesCol) {
    $shareValue = $sharesCol === 'user_id' ? $meId : (string)$meId;
    $where[] = "(n.user_id = :me_owner_where OR EXISTS (SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$sharesCol} = :me_share_where))";
    $params[':me_owner_where'] = $meId;
    $params[':me_share_where'] = $shareValue;
    $isSharedExpr = "EXISTS(SELECT 1 FROM notes_shares s WHERE s.note_id = n.id AND s.{$sharesCol} = :me_share_select) AS is_shared";
    $params[':me_share_select'] = $shareValue;
} else {
    $where[] = 'n.user_id = :me_owner_where';
    $params[':me_owner_where'] = $meId;
    $isSharedExpr = '0 AS is_shared';
}

if ($statusFilter !== '' && $hasMetaTbl) {
    $where[] = 'COALESCE(npm.status, :default_status) = :status_filter';
    $params[':status_filter'] = $statusFilter;
    $params[':default_status'] = NOTES_DEFAULT_STATUS;
}

if ($tagFilter !== '' && $hasTagTbl) {
    $where[] = 'EXISTS (SELECT 1 FROM note_tag_assignments nta JOIN note_tags_catalog tc ON tc.id = nta.tag_id WHERE nta.note_id = n.id AND tc.label = :tag_label)';
    $params[':tag_label'] = $tagFilter;
}

$photoCountExpr   = $hasPhotosTbl ? '(SELECT COUNT(*) FROM note_photos p WHERE p.note_id = n.id) AS photo_count' : '0 AS photo_count';
$commentCountExpr = $hasCommentsTbl ? '(SELECT COUNT(*) FROM note_comments c WHERE c.note_id = n.id) AS comment_count' : '0 AS comment_count';

$selectParts = [
    'n.*',
    '(n.user_id = :me_owner_select) AS is_owner',
    $isSharedExpr,
    $photoCountExpr,
    $commentCountExpr,
];
$params[':me_owner_select'] = $meId;

$joins = '';
if ($hasMetaTbl) {
    $selectParts[] = 'npm.icon AS meta_icon';
    $selectParts[] = 'npm.cover_url AS meta_cover_url';
    $selectParts[] = 'npm.status AS meta_status';
    $selectParts[] = 'npm.properties AS meta_properties';
    $joins .= ' LEFT JOIN note_pages_meta npm ON npm.note_id = n.id';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderParts = [];
if ($hasNoteDate) {
    $orderParts[] = 'n.note_date DESC';
}
if ($hasUpdatedAt) {
    $orderParts[] = 'n.updated_at DESC';
}
if ($hasCreatedAt) {
    $orderParts[] = 'n.created_at DESC';
}
$orderParts[] = 'n.id DESC';
$orderSql = ' ORDER BY ' . implode(', ', $orderParts) . ' LIMIT 200';

$sql = 'SELECT ' . implode(",
          ", $selectParts) . "
        FROM notes n
        {$joins}
        {$whereSql}
        {$orderSql}";

$rows = [];
try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Notes index query failed: ' . $e->getMessage());
    $rows = [];
}
unset($row);

$noteIds = array_map(static fn(array $row) => (int)($row['id'] ?? 0), $rows);
$noteIds = array_values(array_filter($noteIds));
$tagsMap = $noteIds ? notes_fetch_tags_for_notes($noteIds) : [];

$shareMap = [];
$shareLabels = [];
if ($noteIds) {
    $shareCol = notes__shares_column($pdo);
    if ($shareCol) {
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $sqlShares = "SELECT note_id, {$shareCol} AS user_id FROM notes_shares WHERE note_id IN ($placeholders)";
        $stShares = $pdo->prepare($sqlShares);
        $stShares->execute($noteIds);
        $userIds = [];
        while ($shareRow = $stShares->fetch(PDO::FETCH_ASSOC)) {
            $nid = (int)($shareRow['note_id'] ?? 0);
            $uid = (int)($shareRow['user_id'] ?? 0);
            if ($nid <= 0 || $uid <= 0) {
                continue;
            }
            $shareMap[$nid] = $shareMap[$nid] ?? [];
            if (!in_array($uid, $shareMap[$nid], true)) {
                $shareMap[$nid][] = $uid;
                $userIds[] = $uid;
            }
        }
        if ($userIds) {
            $shareLabels = notes_fetch_users_map(array_unique($userIds));
        }
    }
}

foreach ($rows as &$row) {
    $noteId = (int)($row['id'] ?? 0);
    $metaData = [
        'icon'       => null,
        'cover_url'  => null,
        'status'     => NOTES_DEFAULT_STATUS,
        'properties' => notes_default_properties(),
    ];
    if ($hasMetaTbl) {
        if (isset($row['meta_icon']) && $row['meta_icon'] !== '') {
            $metaData['icon'] = $row['meta_icon'];
        }
        if (isset($row['meta_cover_url']) && $row['meta_cover_url'] !== '') {
            $metaData['cover_url'] = $row['meta_cover_url'];
        }
        if (isset($row['meta_status']) && $row['meta_status'] !== '') {
            $metaData['status'] = notes_normalize_status($row['meta_status']);
        }
        if (!empty($row['meta_properties'])) {
            $decoded = json_decode((string)$row['meta_properties'], true);
            $metaData['properties'] = notes_normalize_properties($decoded);
        }
    }
    $row['_meta']        = $metaData;
    $row['_status']      = $metaData['status'];
    $row['_properties']  = $metaData['properties'];
    $row['_tags']        = $tagsMap[$noteId] ?? [];
    $row['_share_ids']   = $shareMap[$noteId] ?? [];
    $row['_share_labels']= array_map(
        static fn($id) => $shareLabels[$id] ?? ('User #' . $id),
        $row['_share_ids']
    );
    $row['_share_count'] = count($row['_share_ids']);
    $row['_owner_label'] = notes_user_label((int)($row['user_id'] ?? 0));
    $row['_excerpt']     = notes__excerpt((string)($row['body'] ?? ''), 220);

    $timestamp = $row['updated_at'] ?? $row['created_at'] ?? $row['note_date'] ?? null;
    $row['_updated_relative'] = $timestamp ? notes__relative_time((string)$timestamp) : '';
    $row['_updated_absolute'] = '';
    if ($timestamp) {
        try {
            $row['_updated_absolute'] = (new DateTimeImmutable((string)$timestamp))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            $row['_updated_absolute'] = (string)$timestamp;
        }
    }
    $row['_can_share']   = notes_can_share($row);
    $row['_payload'] = [
        'id'            => $noteId,
        'title'         => trim((string)($row['title'] ?? '')) ?: 'Untitled',
        'status'        => $row['_status'],
        'statusLabel'   => notes_status_label($row['_status']),
        'statusBadge'   => notes_status_badge_class($row['_status']),
        'icon'          => $metaData['icon'],
        'coverUrl'      => $metaData['cover_url'],
        'excerpt'       => $row['_excerpt'],
        'ownerId'       => (int)($row['user_id'] ?? 0),
        'ownerLabel'    => $row['_owner_label'],
        'isOwner'       => !empty($row['is_owner']),
        'isShared'      => !empty($row['is_shared']) && empty($row['is_owner']),
        'properties'    => $metaData['properties'],
        'tags'          => array_map(static function ($tag) {
            return [
                'label' => (string)($tag['label'] ?? ''),
                'color' => (string)($tag['color'] ?? ''),
            ];
        }, $row['_tags']),
        'shareIds'      => $row['_share_ids'],
        'shareLabels'   => $row['_share_labels'],
        'shareCount'    => $row['_share_count'],
        'photoCount'    => (int)($row['photo_count'] ?? 0),
        'commentCount'  => (int)($row['comment_count'] ?? 0),
        'noteDate'      => $row['note_date'] ?? null,
        'updatedRelative'=> $row['_updated_relative'],
        'updatedAbsolute'=> $row['_updated_absolute'],
        'canShare'      => $row['_can_share'],
        'links'         => [
            'view' => 'view.php?id=' . $noteId,
            'edit' => 'edit.php?id=' . $noteId,
        ],
    ];
}
unset($row);

$focusId = isset($_GET['note']) ? (int)$_GET['note'] : 0;
$activeNote = null;
foreach ($rows as $candidate) {
    if ($focusId > 0 && (int)($candidate['id'] ?? 0) === $focusId) {
        $activeNote = $candidate;
        break;
    }
}
if ($activeNote === null && $rows) {
    $activeNote = $rows[0];
}

$totalNotes       = count($rows);
$ownedCount       = 0;
$sharedCount      = 0;
$photoRichCount   = 0;
$commentRichCount = 0;
$photoTotal       = 0;
$latestTimestamp  = null;
$statusCounts     = array_fill_keys(array_keys($statuses), 0);

foreach ($rows as $row) {
    $isOwner  = !empty($row['is_owner']);
    $isShared = !empty($row['is_shared']) && !$isOwner;
    if ($isOwner) {
        $ownedCount++;
    }
    if ($isShared) {
        $sharedCount++;
    }
    $pc = (int)($row['photo_count'] ?? 0);
    $cc = (int)($row['comment_count'] ?? 0);
    if ($pc > 0) {
        $photoRichCount++;
    }
    if ($cc > 0) {
        $commentRichCount++;
    }
    $photoTotal += $pc;

    $status = $row['_status'] ?? NOTES_DEFAULT_STATUS;
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;

    $candidateTimestamp = $row['updated_at'] ?? $row['created_at'] ?? $row['note_date'] ?? null;
    if ($candidateTimestamp !== null) {
        if ($latestTimestamp === null || strcmp((string)$candidateTimestamp, (string)$latestTimestamp) > 0) {
            $latestTimestamp = (string)$candidateTimestamp;
        }
    }
}

$avgPhotos        = $totalNotes > 0 ? $photoTotal / $totalNotes : 0.0;
$avgPhotosRounded = $avgPhotos > 0 ? round($avgPhotos, 1) : 0.0;
$percentOwned     = $totalNotes > 0 ? round(($ownedCount / $totalNotes) * 100) : 0;
$percentShared    = $totalNotes > 0 ? round(($sharedCount / $totalNotes) * 100) : 0;
$activeCount      = $totalNotes - ($statusCounts['archived'] ?? 0);
$completedCount   = $statusCounts['complete'] ?? 0;
$inProgressCount  = $statusCounts['in_progress'] ?? 0;
$reviewCount      = $statusCounts['review'] ?? 0;
$blockedCount     = $statusCounts['blocked'] ?? 0;

$lastUpdatedRelative = $latestTimestamp ? notes__relative_time($latestTimestamp) : '';
$lastUpdatedAbsolute = '';
if ($latestTimestamp) {
    try {
        $lastUpdatedAbsolute = (new DateTimeImmutable($latestTimestamp))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        $lastUpdatedAbsolute = (string)$latestTimestamp;
    }
}

$statusColumns = [];
foreach ($statuses as $slug => $label) {
    $statusColumns[$slug] = [];
}
foreach ($rows as $row) {
    $slug = $row['_status'] ?? NOTES_DEFAULT_STATUS;
    if (!isset($statusColumns[$slug])) {
        $statusColumns[$slug] = [];
    }
    $statusColumns[$slug][] = $row;
}

$tagOptions = notes_all_tag_options();
$lastUpdateHint = $lastUpdatedRelative !== ''
    ? 'Updated ' . $lastUpdatedRelative
    : 'Waiting for first update';

$templates       = notes_fetch_templates_for_user($meId);
$ownedTemplates  = [];
$sharedTemplates = [];
foreach ($templates as $template) {
    $templateId = (int)($template['id'] ?? 0);
    $details    = $templateId > 0 ? notes_template_share_details($templateId) : [];
    $shareIds   = array_map(static fn($share) => (int)($share['id'] ?? 0), $details);
    $shareLabels= array_map(static fn($share) => (string)($share['label'] ?? ''), $details);
    $normalized = $template;
    $normalized['share_details'] = $details;
    $normalized['share_ids']     = $shareIds;
    $normalized['share_labels']  = $shareLabels;
    $normalized['owner_label']   = notes_user_label((int)($template['owner_id'] ?? ($template['user_id'] ?? 0)));
    $normalized['payload']       = [
        'id'          => $templateId,
        'name'        => (string)($template['name'] ?? ''),
        'title'       => (string)($template['title'] ?? ''),
        'icon'        => $template['icon'] ?? null,
        'coverUrl'    => $template['coverUrl'] ?? null,
        'status'      => $template['status'] ?? NOTES_DEFAULT_STATUS,
        'statusLabel' => notes_status_label($template['status'] ?? NOTES_DEFAULT_STATUS),
        'properties'  => $template['properties'] ?? notes_default_properties(),
        'tags'        => $template['tags'] ?? [],
        'shareIds'    => $shareIds,
        'shareLabels' => $shareLabels,
        'ownerId'     => (int)($template['owner_id'] ?? ($template['user_id'] ?? 0)),
        'ownerLabel'  => $normalized['owner_label'],
        'isOwner'     => !empty($template['is_owner']),
        'sharedFrom'  => $template['shared_from'] ?? null,
    ];
    if (!empty($template['is_owner'])) {
        $ownedTemplates[] = $normalized;
    } else {
        $sharedTemplates[] = $normalized;
    }
}

$shareOptions = [];
foreach (notes_all_users() as $user) {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $label = trim((string)($user['email'] ?? ''));
    if ($label === '') {
        $label = 'User #' . $uid;
    }
    $shareOptions[] = [
        'id'    => $uid,
        'label' => $label,
    ];
}

$csrfToken = csrf_token();

$propertyLabels = notes_property_labels();

$title = 'Notes';
include __DIR__ . '/../includes/header.php';
?>


<section class="obsidian-shell" data-theme="obsidian">
  <header class="obsidian-header">
    <div class="obsidian-header__titles">
      <span class="obsidian-header__eyebrow">Vault overview</span>
      <h1>Notes workspace</h1>
    </div>
    <div class="obsidian-header__actions">
      <button type="button" class="obsidian-header__command" data-command-open>⌘K Command palette</button>
      <a class="btn obsidian-header__new" href="new.php">New note</a>
    </div>
  </header>
  <div class="obsidian-layout" data-index-shell>
    <aside class="obsidian-sidebar">
      <form method="get" action="index.php" class="obsidian-search" autocomplete="off">
        <label class="obsidian-search__field">
          <span>Search vault</span>
          <input type="search" name="q" value="<?= sanitize($search); ?>" placeholder="Title, tag, or text">
        </label>
        <?php if ($statusFilter !== ''): ?>
          <input type="hidden" name="status" value="<?= sanitize($statusFilter); ?>">
        <?php endif; ?>
        <?php if ($tagFilter !== ''): ?>
          <input type="hidden" name="tag" value="<?= sanitize($tagFilter); ?>">
        <?php endif; ?>
        <div class="obsidian-search__meta">
          <span class="obsidian-search__hint">Press <kbd>/</kbd> to focus search</span>
          <div class="obsidian-search__meta-actions">
            <?php if ($statusFilter !== ''): ?>
              <span class="obsidian-search__chip">Status: <?= sanitize($statuses[$statusFilter] ?? notes_status_label($statusFilter)); ?></span>
            <?php endif; ?>
            <?php if ($tagFilter !== ''): ?>
              <span class="obsidian-search__chip">Tag: <?= sanitize($tagFilter); ?></span>
            <?php endif; ?>
            <?php if ($search !== '' || $statusFilter !== '' || $tagFilter !== ''): ?>
              <a class="obsidian-search__reset" href="index.php">Clear filters</a>
            <?php endif; ?>
          </div>
        </div>
      </form>
      <div class="obsidian-summary">
        <div><span>Total notes</span><strong><?= number_format($totalNotes); ?></strong></div>
        <div><span>Shared</span><strong><?= number_format($sharedCount); ?></strong></div>
        <div><span>Comments</span><strong><?= number_format($commentRichCount); ?></strong></div>
        <div><span>Photos</span><strong><?= number_format($photoRichCount); ?></strong></div>
        <div><span>Updated</span><strong><?= sanitize($lastUpdateHint); ?></strong></div>
      </div>
      <div class="obsidian-statuses">
        <h2>Status lanes</h2>
        <ul>
          <?php foreach ($statuses as $slug => $label): ?>
            <li>
              <span class="badge <?= sanitize(notes_status_badge_class($slug)); ?>"><?= sanitize($label); ?></span>
              <span><?= number_format(count($statusColumns[$slug] ?? [])); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="obsidian-templates">
        <header>
          <h2>Templates</h2>
          <p>Capture repeatable note layouts.</p>
        </header>
        <?php if ($ownedTemplates): ?>
          <h3>My templates</h3>
          <ul>
            <?php foreach ($ownedTemplates as $tpl): ?>
              <?php
                $tplId   = (int)($tpl['id'] ?? 0);
                $tplName = trim((string)($tpl['name'] ?? 'Untitled'));
                $tplIcon = trim((string)($tpl['icon'] ?? ''));
                $tplDisplayIcon = $tplIcon !== '' ? $tplIcon : '📄';
                $tplPayload = htmlspecialchars(json_encode($tpl['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              ?>
              <li class="obsidian-template" data-template-id="<?= $tplId; ?>">
                <div class="obsidian-template__row">
                  <a class="obsidian-template__apply" href="new.php?template=<?= $tplId; ?>">
                    <span class="obsidian-template__icon"><?= sanitize($tplDisplayIcon); ?></span>
                    <span>
                      <strong><?= sanitize($tplName); ?></strong>
                      <?php if (!empty($tpl['title'])): ?><em><?= sanitize($tpl['title']); ?></em><?php endif; ?>
                    </span>
                  </a>
                  <button type="button" class="obsidian-template__share" data-template-share-button="<?= $tplId; ?>" data-template-share="<?= $tplPayload; ?>">Share</button>
                </div>
                <div class="obsidian-template__sharelist" data-template-share-list="<?= $tplId; ?>">
                  <?php if (!empty($tpl['share_labels'])): ?>
                    <?php foreach ($tpl['share_labels'] as $label): ?>
                      <span class="obsidian-pill"><?= sanitize($label); ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="obsidian-pill is-muted">Private</span>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="obsidian-template__empty">Save a note as a template to jump-start new pages.</p>
        <?php endif; ?>
        <?php if ($sharedTemplates): ?>
          <h3>Shared with me</h3>
          <ul>
            <?php foreach ($sharedTemplates as $tpl): ?>
              <?php
                $tplId   = (int)($tpl['id'] ?? 0);
                $tplName = trim((string)($tpl['name'] ?? 'Untitled'));
                $tplIcon = trim((string)($tpl['icon'] ?? ''));
                $tplDisplayIcon = $tplIcon !== '' ? $tplIcon : '📄';
              ?>
              <li class="obsidian-template obsidian-template--shared" data-template-id="<?= $tplId; ?>">
                <a class="obsidian-template__apply" href="new.php?template=<?= $tplId; ?>">
                  <span class="obsidian-template__icon"><?= sanitize($tplDisplayIcon); ?></span>
                  <span>
                    <strong><?= sanitize($tplName); ?></strong>
                    <?php if (!empty($tpl['shared_from'])): ?><em>Shared by <?= sanitize($tpl['shared_from']); ?></em><?php endif; ?>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </aside>
    <div class="obsidian-main">
      <div class="obsidian-note-list" data-note-list>
        <?php if (!$rows): ?>
          <div class="obsidian-empty">
            <p>No notes yet.</p>
            <a class="btn obsidian-primary" href="new.php">Create a note</a>
          </div>
        <?php else: ?>
          <?php foreach ($rows as $note): ?>
            <?php
              $noteId = (int)($note['id'] ?? 0);
              $payloadJson = htmlspecialchars(json_encode($note['_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              $title = trim((string)($note['title'] ?? ''));
              if ($title === '') { $title = 'Untitled'; }
              $icon = trim((string)($note['_meta']['icon'] ?? ''));
              if ($icon === '') {
                  $firstChar = function_exists('mb_substr') ? mb_substr($title, 0, 1, 'UTF-8') : substr($title, 0, 1);
                  $icon = $firstChar !== '' ? strtoupper($firstChar) : '📝';
              }
              $status = $note['_status'] ?? NOTES_DEFAULT_STATUS;
              $statusLabel = notes_status_label($status);
              $statusClass = notes_status_badge_class($status);
            ?>
            <article class="obsidian-note<?= ($activeNote && (int)$activeNote['id'] === $noteId) ? ' is-active' : ''; ?>" data-note-id="<?= $noteId; ?>" data-note='<?= $payloadJson; ?>' data-note-item>
              <header class="obsidian-note__header">
                <span class="obsidian-note__icon"><?= sanitize($icon); ?></span>
                <div class="obsidian-note__titles">
                  <h3><?= sanitize($title); ?></h3>
                  <div class="obsidian-note__meta">
                    <span class="badge <?= sanitize($statusClass); ?>"><?= sanitize($statusLabel); ?></span>
                    <?php if ($note['_updated_relative']): ?>
                      <span class="obsidian-note__timestamp"><?= sanitize($note['_updated_relative']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($note['_is_shared'])): ?>
                      <span class="obsidian-note__shared">Shared</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="obsidian-note__counts">
                  <span class="obsidian-note__pill" data-note-share-count="<?= $noteId; ?>">👥 <?= number_format($note['_share_count']); ?></span>
                  <span class="obsidian-note__pill">💬 <?= number_format((int)($note['comment_count'] ?? 0)); ?></span>
                  <span class="obsidian-note__pill">📸 <?= number_format((int)($note['photo_count'] ?? 0)); ?></span>
                </div>
              </header>
              <?php if ($note['_excerpt']): ?>
                <p class="obsidian-note__excerpt"><?= sanitize($note['_excerpt']); ?></p>
              <?php endif; ?>
              <?php if ($note['_tags']): ?>
                <div class="obsidian-note__tags">
                  <?php foreach ($note['_tags'] as $tag): $color = $tag['color'] ?? '#6366F1'; ?>
                    <span class="obsidian-tag" style="--tag-color: <?= sanitize($color); ?>"><?= sanitize($tag['label'] ?? ''); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <aside class="obsidian-preview" data-note-preview>
      <?php if ($activeNote): ?>
        <?php
          $payload = $activeNote['_payload'];
          $previewTitle = trim((string)($payload['title'] ?? 'Untitled'));
          if ($previewTitle === '') { $previewTitle = 'Untitled'; }
          $previewIcon = trim((string)($payload['icon'] ?? ''));
          if ($previewIcon === '') {
              $firstChar = function_exists('mb_substr') ? mb_substr($previewTitle, 0, 1, 'UTF-8') : substr($previewTitle, 0, 1);
              $previewIcon = $firstChar !== '' ? strtoupper($firstChar) : '📝';
          }
        ?>
        <div class="obsidian-preview__scroll" data-active-note-id="<?= (int)($payload['id'] ?? 0); ?>">
          <div class="obsidian-preview__header">
            <span class="obsidian-preview__icon" data-preview-icon><?= sanitize($previewIcon); ?></span>
            <div>
              <span class="badge <?= sanitize($payload['statusBadge']); ?>" data-preview-status><?= sanitize($payload['statusLabel']); ?></span>
              <h2 data-preview-title><?= sanitize($previewTitle); ?></h2>
              <?php if (!empty($payload['updatedRelative'])): ?>
                <p class="obsidian-preview__timestamp">Updated <?= sanitize($payload['updatedRelative']); ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="obsidian-preview__actions">
            <a class="btn obsidian-primary" href="<?= sanitize($payload['links']['view']); ?>" data-preview-view>Open note</a>
            <a class="btn obsidian-btn" href="<?= sanitize($payload['links']['edit']); ?>" data-preview-edit>Edit</a>
            <?php if (!empty($payload['canShare'])): ?>
              <button type="button" class="btn obsidian-btn--ghost" data-open-note-share="<?= (int)($payload['id'] ?? 0); ?>">Manage access</button>
            <?php endif; ?>
          </div>
          <dl class="obsidian-preview__meta">
            <div>
              <dt>Owner</dt>
              <dd data-preview-owner><?= sanitize($payload['ownerLabel']); ?></dd>
            </div>
            <div>
              <dt>Photos</dt>
              <dd data-preview-photos><?= number_format((int)($payload['photoCount'] ?? 0)); ?></dd>
            </div>
            <div>
              <dt>Comments</dt>
              <dd data-preview-comments><?= number_format((int)($payload['commentCount'] ?? 0)); ?></dd>
            </div>
            <div>
              <dt>Note date</dt>
              <dd data-preview-date><?= sanitize(notes__format_date($payload['noteDate'] ?? '')); ?></dd>
            </div>
          </dl>
          <div class="obsidian-preview__properties" data-preview-properties>
            <?php foreach ($payload['properties'] as $key => $value): $value = trim((string)$value); if ($value === '') continue; ?>
              <div><span><?= sanitize($propertyLabels[$key] ?? ucfirst($key)); ?></span><strong><?= sanitize($value); ?></strong></div>
            <?php endforeach; ?>
          </div>
          <div class="obsidian-preview__tags" data-preview-tags>
            <?php foreach ($payload['tags'] as $tag): $color = $tag['color'] ?? '#6366F1'; ?>
              <span class="obsidian-tag" style="--tag-color: <?= sanitize($color); ?>"><?= sanitize($tag['label'] ?? ''); ?></span>
            <?php endforeach; ?>
          </div>
          <div class="obsidian-preview__shares">
            <h3>Collaborators</h3>
            <div class="obsidian-preview__sharelist" data-preview-shares>
              <?php if (!empty($payload['shareLabels'])): ?>
                <?php foreach ($payload['shareLabels'] as $label): ?>
                  <span class="obsidian-pill"><?= sanitize($label); ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="obsidian-pill is-muted">Private</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="obsidian-preview__empty">
          <p>Select a note to see its details.</p>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</section>

<div class="obsidian-modal hidden" id="commandPalette" data-modal>
  <div class="obsidian-modal__overlay" data-modal-close></div>
  <div class="obsidian-modal__dialog" role="dialog" aria-modal="true">
    <header>
      <input type="search" placeholder="Jump to note..." data-command-input>
    </header>
    <ul class="obsidian-modal__results" data-command-results></ul>
    <footer class="obsidian-modal__hint">Enter to open · ↑↓ to navigate · Esc to close</footer>
  </div>
</div>

<div class="share-modal hidden" id="noteShareModal" data-modal>
  <div class="share-modal__overlay" data-modal-close></div>
  <div class="share-modal__dialog" role="dialog" aria-modal="true">
    <header class="share-modal__header">
      <div>
        <h3>Share note</h3>
        <p class="share-modal__subtitle" data-share-note-title></p>
      </div>
      <button type="button" class="share-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="share-modal__form" data-note-share-form>
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
      <input type="hidden" name="update_note_shares" value="1">
      <input type="hidden" name="note_id" value="">
      <div class="share-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <label class="share-modal__option" data-share-option data-user-id="<?= (int)$option['id']; ?>" data-label="<?= sanitize($option['label']); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?= (int)$option['id']; ?>">
            <span><?= sanitize($option['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="share-modal__footer">
        <span class="share-modal__status" data-share-status></span>
        <button type="submit" class="btn obsidian-primary">Save access</button>
      </div>
    </form>
  </div>
</div>

<div class="share-modal hidden" id="templateShareModal" data-modal>
  <div class="share-modal__overlay" data-modal-close></div>
  <div class="share-modal__dialog" role="dialog" aria-modal="true">
    <header class="share-modal__header">
      <div>
        <h3>Share template</h3>
        <p class="share-modal__subtitle" data-share-template-title></p>
      </div>
      <button type="button" class="share-modal__close" data-modal-close>&times;</button>
    </header>
    <form method="post" class="share-modal__form" data-template-share-form>
      <input type="hidden" name="<?= CSRF_TOKEN_NAME; ?>" value="<?= sanitize($csrfToken); ?>">
      <input type="hidden" name="update_template_shares" value="1">
      <input type="hidden" name="template_id" value="">
      <div class="share-modal__body">
        <?php foreach ($shareOptions as $option): ?>
          <label class="share-modal__option" data-template-share-option data-user-id="<?= (int)$option['id']; ?>" data-label="<?= sanitize($option['label']); ?>">
            <input type="checkbox" name="shared_ids[]" value="<?= (int)$option['id']; ?>">
            <span><?= sanitize($option['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="share-modal__footer">
        <span class="share-modal__status" data-template-share-status></span>
        <button type="submit" class="btn obsidian-primary">Save access</button>
      </div>
    </form>
  </div>
</div>

<style>
.obsidian-shell{position:relative;background:radial-gradient(circle at top left,#1e293b,#0f172a);color:#e2e8f0;border-radius:24px;padding:2rem 2.25rem;margin-bottom:2rem;box-shadow:0 30px 60px rgba(15,23,42,.35);}
.obsidian-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.75rem;flex-wrap:wrap;}
.obsidian-header__titles h1{margin:0;font-size:1.9rem;font-weight:700;color:#f8fafc;}
.obsidian-header__eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:.78rem;color:#94a3b8;display:block;margin-bottom:.25rem;}
.obsidian-header__actions{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;}
.obsidian-header__command{background:rgba(148,163,184,.14);border:1px solid rgba(148,163,184,.3);color:#e2e8f0;border-radius:999px;padding:.55rem 1.1rem;font-size:.95rem;cursor:pointer;transition:.2s ease;}
.obsidian-header__command:hover{background:rgba(148,163,184,.24);}
.obsidian-header__new{background:#6366f1;color:#f8fafc;border-radius:999px;padding:.6rem 1.4rem;font-weight:600;box-shadow:0 14px 30px rgba(99,102,241,.4);}

.obsidian-layout{display:grid;gap:1.5rem;grid-template-columns:320px minmax(0,1fr) 360px;align-items:start;}
.obsidian-sidebar{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.12);border-radius:18px;padding:1.4rem;display:grid;gap:1.4rem;}
.obsidian-main{background:rgba(15,23,42,.35);border:1px solid rgba(148,163,184,.1);border-radius:18px;padding:1.2rem;min-height:520px;}
.obsidian-preview{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.12);border-radius:18px;padding:1.4rem;min-height:520px;}

.obsidian-search{display:grid;gap:1rem;background:rgba(15,23,42,.5);border:1px solid rgba(148,163,184,.12);border-radius:16px;padding:1rem;}
.obsidian-search__field{display:grid;gap:.4rem;font-size:.85rem;color:#cbd5f5;}
.obsidian-search input{background:rgba(15,23,42,.8);border:1px solid rgba(148,163,184,.2);border-radius:12px;padding:.55rem .75rem;color:#e2e8f0;font-size:.95rem;}
.obsidian-search__meta{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;color:#94a3b8;font-size:.8rem;}
.obsidian-search__hint{display:flex;align-items:center;gap:.35rem;}
.obsidian-search__hint kbd{background:rgba(148,163,184,.2);border-radius:6px;padding:.15rem .35rem;font-family:'JetBrains Mono',monospace;font-size:.75rem;color:#e2e8f0;}
.obsidian-search__meta-actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.obsidian-search__chip{background:rgba(99,102,241,.25);color:#e0e7ff;border-radius:999px;padding:.25rem .65rem;font-size:.75rem;}
.obsidian-search__reset{color:#cbd5f5;text-decoration:none;border-bottom:1px solid transparent;transition:border-color .2s ease,color .2s ease;}
.obsidian-search__reset:hover{color:#e0e7ff;border-color:#6366f1;}
.obsidian-btn{background:rgba(99,102,241,.25);border:1px solid rgba(99,102,241,.5);color:#e0e7ff;border-radius:999px;padding:.45rem 1.1rem;cursor:pointer;}
.obsidian-btn--ghost{background:transparent;border:1px solid rgba(148,163,184,.3);color:#cbd5f5;border-radius:999px;padding:.45rem 1.1rem;cursor:pointer;}
.obsidian-primary{background:#6366f1;border:none;color:#f8fafc;border-radius:999px;padding:.55rem 1.3rem;font-weight:600;box-shadow:0 12px 24px rgba(99,102,241,.35);cursor:pointer;}

.obsidian-summary{display:grid;gap:.45rem;background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.14);border-radius:14px;padding:1rem;font-size:.9rem;}
.obsidian-summary span{color:#94a3b8;font-size:.78rem;text-transform:uppercase;letter-spacing:.1em;display:block;}
.obsidian-summary strong{color:#f8fafc;font-size:1.05rem;}

.obsidian-statuses ul{list-style:none;margin:0;padding:0;display:grid;gap:.45rem;}
.obsidian-statuses li{display:flex;justify-content:space-between;align-items:center;background:rgba(15,23,42,.45);border-radius:12px;padding:.5rem .7rem;border:1px solid rgba(148,163,184,.12);font-size:.9rem;}

.obsidian-templates header h2{margin:0;font-size:1.05rem;color:#f1f5f9;}
.obsidian-templates header p{margin:.3rem 0 0;color:#94a3b8;font-size:.85rem;}
.obsidian-templates h3{margin:.8rem 0 .4rem;font-size:.9rem;color:#cbd5f5;text-transform:uppercase;letter-spacing:.12em;}
.obsidian-template__empty{margin:0;font-size:.85rem;color:#94a3b8;}
.obsidian-templates ul{list-style:none;margin:0;padding:0;display:grid;gap:.6rem;}
.obsidian-template{background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.16);border-radius:14px;padding:.75rem .85rem;display:grid;gap:.5rem;}
.obsidian-template--shared{background:rgba(14,116,144,.25);border-color:rgba(45,212,191,.35);}
.obsidian-template__row{display:flex;justify-content:space-between;align-items:center;gap:.6rem;}
.obsidian-template__apply{text-decoration:none;color:#e2e8f0;display:flex;gap:.65rem;align-items:center;}
.obsidian-template__icon{width:34px;height:34px;border-radius:10px;background:rgba(99,102,241,.2);display:grid;place-items:center;font-size:1.1rem;}
.obsidian-template__apply strong{display:block;font-weight:600;}
.obsidian-template__apply em{display:block;font-size:.8rem;color:#94a3b8;font-style:normal;}
.obsidian-template__share{background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.45);color:#e0e7ff;border-radius:999px;padding:.35rem .9rem;font-size:.8rem;cursor:pointer;}
.obsidian-template__sharelist{display:flex;gap:.35rem;flex-wrap:wrap;}

.obsidian-note-list{display:grid;gap:.75rem;}
.obsidian-note{background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.14);border-radius:16px;padding:1rem;display:grid;gap:.65rem;cursor:pointer;transition:transform .18s ease, border-color .18s ease, box-shadow .18s ease;}
.obsidian-note:hover{transform:translateY(-2px);border-color:rgba(99,102,241,.4);box-shadow:0 16px 32px rgba(15,23,42,.3);}
.obsidian-note.is-active{border-color:#6366f1;box-shadow:0 20px 40px rgba(99,102,241,.45);}
.obsidian-note__header{display:flex;gap:.75rem;align-items:flex-start;justify-content:space-between;}
.obsidian-note__icon{width:42px;height:42px;border-radius:12px;background:rgba(99,102,241,.18);display:grid;place-items:center;font-size:1.2rem;}
.obsidian-note__titles h3{margin:0;font-size:1.1rem;color:#f8fafc;}
.obsidian-note__meta{display:flex;gap:.45rem;align-items:center;font-size:.78rem;color:#94a3b8;flex-wrap:wrap;}
.obsidian-note__timestamp{color:#cbd5f5;}
.obsidian-note__shared{color:#22d3ee;font-weight:600;}
.obsidian-note__counts{display:flex;gap:.4rem;align-items:center;}
.obsidian-note__pill{background:rgba(148,163,184,.18);border-radius:999px;padding:.25rem .65rem;font-size:.78rem;color:#e2e8f0;}
.obsidian-note__excerpt{margin:0;font-size:.92rem;color:#cbd5f5;}
.obsidian-note__tags{display:flex;gap:.4rem;flex-wrap:wrap;}
.obsidian-tag{display:inline-flex;align-items:center;gap:.35rem;background:rgba(99,102,241,.18);border-radius:999px;padding:.2rem .6rem;font-size:.78rem;color:#e0e7ff;}
.obsidian-tag::before{content:'';width:8px;height:8px;border-radius:50%;background:var(--tag-color,#6366f1);}

.obsidian-preview__scroll{display:grid;gap:1rem;max-height:540px;overflow:auto;padding-right:.3rem;}
.obsidian-preview__header{display:flex;gap:1rem;align-items:center;}
.obsidian-preview__icon{width:52px;height:52px;border-radius:14px;background:rgba(99,102,241,.2);display:grid;place-items:center;font-size:1.4rem;}
.obsidian-preview__timestamp{margin:.3rem 0 0;color:#94a3b8;font-size:.85rem;}
.obsidian-preview__actions{display:flex;gap:.6rem;flex-wrap:wrap;}
.obsidian-preview__meta{display:grid;gap:.6rem;grid-template-columns:repeat(2,minmax(0,1fr));background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.12);border-radius:14px;padding:.85rem;}
.obsidian-preview__meta dt{margin:0;font-size:.75rem;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;}
.obsidian-preview__meta dd{margin:0;font-size:.95rem;color:#f8fafc;}
.obsidian-preview__properties{display:grid;gap:.5rem;background:rgba(15,23,42,.45);border:1px solid rgba(148,163,184,.12);border-radius:14px;padding:.85rem;font-size:.9rem;}
.obsidian-preview__properties span{display:block;font-size:.75rem;text-transform:uppercase;color:#94a3b8;letter-spacing:.1em;}
.obsidian-preview__properties strong{color:#f8fafc;}
.obsidian-preview__tags{display:flex;gap:.4rem;flex-wrap:wrap;}
.obsidian-preview__shares h3{margin:0 0 .4rem;font-size:1rem;color:#f8fafc;}
.obsidian-preview__sharelist{display:flex;gap:.4rem;flex-wrap:wrap;}
.obsidian-preview__empty{display:grid;place-items:center;height:100%;font-size:.95rem;color:#94a3b8;text-align:center;}

.obsidian-empty{text-align:center;display:grid;gap:.75rem;justify-items:center;background:rgba(15,23,42,.45);border:1px dashed rgba(148,163,184,.3);border-radius:16px;padding:2rem;}

.obsidian-pill{display:inline-flex;align-items:center;background:rgba(99,102,241,.2);border-radius:999px;padding:.3rem .7rem;font-size:.8rem;color:#e0e7ff;}
.obsidian-pill.is-muted{background:rgba(148,163,184,.15);color:#cbd5f5;}

.share-modal{position:fixed;inset:0;display:grid;place-items:center;z-index:40;padding:1.5rem;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);transition:opacity .2s ease;}
.share-modal.hidden{opacity:0;pointer-events:none;}
.share-modal__overlay{position:absolute;inset:0;}
.share-modal__dialog{position:relative;z-index:1;width:min(420px,100%);background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,.6);display:grid;}
.share-modal__header{padding:1.1rem 1.4rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;border-bottom:1px solid rgba(148,163,184,.2);}
.share-modal__header h3{margin:0;color:#f8fafc;font-size:1.1rem;}
.share-modal__subtitle{margin:.25rem 0 0;font-size:.85rem;color:#94a3b8;}
.share-modal__close{background:transparent;border:none;color:#cbd5f5;font-size:1.5rem;cursor:pointer;}
.share-modal__form{display:grid;gap:0;}
.share-modal__body{padding:1.1rem 1.4rem;display:grid;gap:.5rem;max-height:320px;overflow:auto;}
.share-modal__option{display:flex;gap:.6rem;align-items:center;background:rgba(15,23,42,.6);border:1px solid rgba(148,163,184,.2);border-radius:12px;padding:.6rem .75rem;color:#e2e8f0;}
.share-modal__option input{width:1rem;height:1rem;}
.share-modal__option.is-disabled{opacity:.55;cursor:not-allowed;}
.share-modal__option.is-disabled input{pointer-events:none;}
.share-modal__footer{padding:1rem 1.4rem;border-top:1px solid rgba(148,163,184,.2);display:flex;justify-content:space-between;align-items:center;gap:1rem;}
.share-modal__status{font-size:.85rem;color:#94a3b8;}
.share-modal__status.is-error{color:#f87171;}

.obsidian-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:1.5rem;background:rgba(15,23,42,.6);backdrop-filter:blur(8px);transition:opacity .2s ease;}
.obsidian-modal.hidden{opacity:0;pointer-events:none;}
.obsidian-modal__overlay{position:absolute;inset:0;}
.obsidian-modal__dialog{position:relative;z-index:1;width:min(520px,100%);background:#111827;border:1px solid rgba(148,163,184,.2);border-radius:18px;display:grid;gap:.75rem;padding:1rem 1.1rem 1.25rem;box-shadow:0 30px 60px rgba(15,23,42,.55);}
.obsidian-modal__dialog header input{width:100%;background:rgba(15,23,42,.65);border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:.6rem .8rem;color:#f8fafc;font-size:1rem;}
.obsidian-modal__results{list-style:none;margin:0;padding:0;display:grid;gap:.35rem;max-height:320px;overflow:auto;}
.obsidian-modal__results li{border-radius:12px;padding:.55rem .75rem;background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.18);color:#e2e8f0;cursor:pointer;}
.obsidian-modal__results li.is-active{border-color:#6366f1;background:rgba(99,102,241,.2);}
.obsidian-modal__hint{font-size:.8rem;color:#94a3b8;}

.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.3rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;color:#0f172a;}
.badge--blue{background:#bfdbfe;color:#1d4ed8;}
.badge--indigo{background:#c7d2fe;color:#4338ca;}
.badge--purple{background:#e9d5ff;color:#6b21a8;}
.badge--orange{background:#fed7aa;color:#c2410c;}
.badge--green{background:#bbf7d0;color:#15803d;}
.badge--slate{background:#cbd5f5;color:#1e293b;}
.badge--danger{background:#fecaca;color:#991b1b;}
.badge--amber{background:#fde68a;color:#b45309;}
.badge--teal{background:#5eead4;color:#0f766e;}

@media (max-width:1180px){
  .obsidian-layout{grid-template-columns:260px minmax(0,1fr);}
  .obsidian-preview{grid-column:1 / -1;}
}
@media (max-width:920px){
  .obsidian-layout{grid-template-columns:minmax(0,1fr);}
  .obsidian-sidebar,.obsidian-main,.obsidian-preview{grid-column:1 / -1;}
}
</style>

<script>
(() => {
  const shell = document.querySelector('[data-index-shell]');
  if (!shell) return;

  const noteState = new Map();
  const noteElements = Array.from(shell.querySelectorAll('[data-note-item]'));
  noteElements.forEach((el) => {
    try {
      const raw = el.getAttribute('data-note') || '{}';
      const data = JSON.parse(raw);
      if (data && typeof data.id !== 'undefined') {
        noteState.set(String(data.id), data);
      }
    } catch (err) {
      console.warn('Failed to parse note payload', err);
    }
  });

  const preview = document.querySelector('[data-note-preview]');
  const previewRoot = preview ? preview.querySelector('[data-active-note-id]') : null;
  const previewTitle = preview ? preview.querySelector('[data-preview-title]') : null;
  const previewStatus = preview ? preview.querySelector('[data-preview-status]') : null;
  const previewIcon = preview ? preview.querySelector('[data-preview-icon]') : null;
  const previewOwner = preview ? preview.querySelector('[data-preview-owner]') : null;
  const previewPhotos = preview ? preview.querySelector('[data-preview-photos]') : null;
  const previewComments = preview ? preview.querySelector('[data-preview-comments]') : null;
  const previewDate = preview ? preview.querySelector('[data-preview-date]') : null;
  const previewProperties = preview ? preview.querySelector('[data-preview-properties]') : null;
  const previewTags = preview ? preview.querySelector('[data-preview-tags]') : null;
  const previewShares = preview ? preview.querySelector('[data-preview-shares]') : null;
  const previewView = preview ? preview.querySelector('[data-preview-view]') : null;
  const previewEdit = preview ? preview.querySelector('[data-preview-edit]') : null;
  const previewShareBtn = preview ? preview.querySelector('[data-open-note-share]') : null;

  function renderPreview(payload) {
    if (!payload || !preview) return;
    if (previewRoot) previewRoot.setAttribute('data-active-note-id', String(payload.id || ''));
    if (previewTitle) previewTitle.textContent = payload.title || 'Untitled';
    if (previewStatus) {
      previewStatus.textContent = payload.statusLabel || '';
      previewStatus.className = 'badge ' + (payload.statusBadge || 'badge--indigo');
    }
    if (previewIcon) previewIcon.textContent = payload.icon || '📝';
    if (previewOwner) previewOwner.textContent = payload.ownerLabel || '';
    if (previewPhotos) previewPhotos.textContent = String(payload.photoCount || 0);
    if (previewComments) previewComments.textContent = String(payload.commentCount || 0);
    if (previewDate) previewDate.textContent = payload.noteDate || '';
    if (previewView && payload.links) previewView.setAttribute('href', payload.links.view || '#');
    if (previewEdit && payload.links) previewEdit.setAttribute('href', payload.links.edit || '#');
    if (previewShareBtn) {
      if (payload.canShare) {
        previewShareBtn.dataset.openNoteShare = String(payload.id || '');
        previewShareBtn.style.display = '';
      } else {
        previewShareBtn.style.display = 'none';
      }
    }
    if (previewProperties) {
      previewProperties.innerHTML = '';
      if (payload.properties) {
        Object.entries(payload.properties).forEach(([key, value]) => {
          const val = String(value || '').trim();
          if (!val) return;
          const row = document.createElement('div');
          const label = document.createElement('span');
          label.textContent = key.replace(/_/g, ' ');
          const strong = document.createElement('strong');
          strong.textContent = val;
          row.appendChild(label);
          row.appendChild(strong);
          previewProperties.appendChild(row);
        });
      }
    }
    if (previewTags) {
      previewTags.innerHTML = '';
      (payload.tags || []).forEach((tag) => {
        const span = document.createElement('span');
        span.className = 'obsidian-tag';
        if (tag && tag.color) span.style.setProperty('--tag-color', tag.color);
        span.textContent = tag && tag.label ? tag.label : '';
        previewTags.appendChild(span);
      });
    }
    if (previewShares) {
      previewShares.innerHTML = '';
      const shares = Array.isArray(payload.shareLabels) ? payload.shareLabels : [];
      if (!shares.length) {
        const pill = document.createElement('span');
        pill.className = 'obsidian-pill is-muted';
        pill.textContent = 'Private';
        previewShares.appendChild(pill);
      } else {
        shares.forEach((label) => {
          const pill = document.createElement('span');
          pill.className = 'obsidian-pill';
          pill.textContent = label;
          previewShares.appendChild(pill);
        });
      }
    }
  }

  function activateNote(el) {
    if (!el) return;
    noteElements.forEach((node) => node.classList.remove('is-active'));
    el.classList.add('is-active');
    const noteId = el.getAttribute('data-note-id');
    const payload = noteState.get(String(noteId));
    if (payload) renderPreview(payload);
  }

  noteElements.forEach((el) => {
    el.addEventListener('click', (event) => {
      event.preventDefault();
      activateNote(el);
    });
  });

  const initial = noteElements.find((el) => el.classList.contains('is-active'));
  if (initial) activateNote(initial);

  const commandModal = document.getElementById('commandPalette');
  const commandInput = commandModal ? commandModal.querySelector('[data-command-input]') : null;
  const commandResults = commandModal ? commandModal.querySelector('[data-command-results]') : null;
  let commandItems = [];
  let commandIndex = -1;

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  }

  function openCommandPalette() {
    if (!commandModal || !commandResults) return;
    commandResults.innerHTML = '';
    commandItems = Array.from(noteState.values()).sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
    commandItems.forEach((item, idx) => {
      const li = document.createElement('li');
      li.textContent = item.title || 'Untitled';
      li.dataset.noteId = String(item.id || '');
      if (!idx) {
        li.classList.add('is-active');
        commandIndex = 0;
      }
      li.addEventListener('click', () => {
        closeModal(commandModal);
        setTimeout(() => {
          const target = shell.querySelector(`[data-note-id="${item.id}"]`);
          if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            activateNote(target);
          }
        }, 40);
      });
      commandResults.appendChild(li);
    });
    openModal(commandModal);
    if (commandInput) {
      commandInput.value = '';
      commandInput.focus();
    }
  }

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      openCommandPalette();
    }
    if (event.key === 'Escape') {
      closeModal(commandModal);
      closeModal(noteShareModal);
      closeModal(templateShareModal);
    }
  });

  if (commandModal && commandResults && commandInput) {
    commandInput.addEventListener('input', () => {
      const query = commandInput.value.toLowerCase();
      Array.from(commandResults.children).forEach((li, idx) => {
        const match = li.textContent.toLowerCase().includes(query);
        li.style.display = match ? '' : 'none';
        if (match && commandIndex === -1) {
          commandIndex = idx;
          li.classList.add('is-active');
        }
      });
    });
    commandModal.addEventListener('keydown', (event) => {
      const visible = Array.from(commandResults.children).filter((li) => li.style.display !== 'none');
      if (!visible.length) return;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        commandIndex = (commandIndex + 1) % visible.length;
        visible.forEach((li, idx) => li.classList.toggle('is-active', idx === commandIndex));
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        commandIndex = (commandIndex - 1 + visible.length) % visible.length;
        visible.forEach((li, idx) => li.classList.toggle('is-active', idx === commandIndex));
      } else if (event.key === 'Enter') {
        event.preventDefault();
        const target = visible[commandIndex];
        if (target) target.click();
      }
    });
  }

  const noteShareModal = document.getElementById('noteShareModal');
  const noteShareForm = noteShareModal ? noteShareModal.querySelector('[data-note-share-form]') : null;
  const noteShareStatus = noteShareModal ? noteShareModal.querySelector('[data-share-status]') : null;
  const noteShareTitle = noteShareModal ? noteShareModal.querySelector('[data-share-note-title]') : null;
  const noteShareOptions = noteShareModal ? Array.from(noteShareModal.querySelectorAll('[data-share-option]')) : [];

  function syncOptions(options, selected, ownerId) {
    options.forEach((option) => {
      const checkbox = option.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      const uid = Number(option.dataset.userId || '0');
      checkbox.checked = selected.includes(uid);
      if (uid === ownerId) {
        checkbox.checked = true;
        checkbox.disabled = true;
        option.classList.add('is-disabled');
      } else {
        checkbox.disabled = false;
        option.classList.remove('is-disabled');
      }
    });
  }

  function openNoteShare(noteId) {
    if (!noteShareModal) return;
    const payload = noteState.get(String(noteId));
    if (!payload) return;
    if (noteShareForm) {
      noteShareForm.querySelector('input[name="note_id"]').value = String(noteId);
    }
    if (noteShareTitle) noteShareTitle.textContent = payload.title || 'Untitled';
    syncOptions(noteShareOptions, payload.shareIds || [], Number(payload.ownerId || 0));
    if (noteShareStatus) {
      noteShareStatus.textContent = '';
      noteShareStatus.classList.remove('is-error');
    }
    openModal(noteShareModal);
    const first = noteShareModal.querySelector('input[type="checkbox"]');
    if (first) first.focus();
  }

  function updateNoteShares(noteId, shares) {
    const payload = noteState.get(String(noteId));
    if (!payload) return;
    const ids = shares.map((entry) => Number(entry.id || entry));
    const labels = shares.map((entry) => entry.label || String(entry.id));
    payload.shareIds = ids;
    payload.shareLabels = labels;
    payload.shareCount = ids.length;
    noteState.set(String(noteId), payload);
    const pill = document.querySelector(`[data-note-share-count="${noteId}"]`);
    if (pill) pill.textContent = `👥 ${ids.length}`;
    if (previewRoot && String(previewRoot.getAttribute('data-active-note-id')) === String(noteId)) {
      renderPreview(payload);
    }
  }

  if (noteShareForm) {
    noteShareForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(noteShareForm);
      fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then((res) => {
        if (!res.ok) throw new Error('Request failed');
        return res.json();
      }).then((data) => {
        if (data && data.ok) {
          updateNoteShares(data.note_id, data.shares || []);
          if (noteShareStatus) {
            noteShareStatus.textContent = 'Access updated';
            noteShareStatus.classList.remove('is-error');
          }
          setTimeout(() => closeModal(noteShareModal), 350);
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

  document.querySelectorAll('[data-open-note-share]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      openNoteShare(btn.getAttribute('data-open-note-share'));
    });
  });

  const templateShareModal = document.getElementById('templateShareModal');
  const templateShareForm = templateShareModal ? templateShareModal.querySelector('[data-template-share-form]') : null;
  const templateShareStatus = templateShareModal ? templateShareModal.querySelector('[data-template-share-status]') : null;
  const templateShareTitle = templateShareModal ? templateShareModal.querySelector('[data-share-template-title]') : null;
  const templateShareOptions = templateShareModal ? Array.from(templateShareModal.querySelectorAll('[data-template-share-option]')) : [];
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
        // ignore serialization issues
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
    templateShareOptions.forEach((option) => {
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
    document.querySelectorAll('[data-template-share]').forEach((button) => {
      const raw = button.getAttribute('data-template-share') || '{}';
      let parsed = {};
      try {
        parsed = JSON.parse(raw);
      } catch (err) {
        console.warn('Template payload error', err);
      }
      const templateId = button.getAttribute('data-template-share-button') || String(parsed.id || '');
      if (templateId) {
        applyTemplateState(templateId, parsed);
      }
      button.addEventListener('click', (event) => {
        event.preventDefault();
        if (!templateShareForm || !templateIdInput) return;
        const key = String(templateId || '');
        const state = templateState.get(key) || applyTemplateState(key, parsed);
        templateIdInput.value = key;
        if (templateShareTitle) templateShareTitle.textContent = state.name || 'Template';
        syncTemplateOptions(state);
        if (templateShareStatus) {
          templateShareStatus.textContent = '';
          templateShareStatus.classList.remove('is-error');
        }
        openModal(templateShareModal);
        const first = templateShareModal.querySelector('input[type="checkbox"]');
        if (first) first.focus();
      });
    });

    if (templateShareForm) {
      templateShareForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const formData = new FormData(templateShareForm);
        fetch('index.php', {
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
            const state = applyTemplateState(templateId, { shareIds, shareLabels });
            renderTemplateShares(templateId, normalized);
            syncTemplateOptions(state);
            if (templateShareStatus) {
              templateShareStatus.textContent = 'Template access updated';
              templateShareStatus.classList.remove('is-error');
            }
            setTimeout(() => closeModal(templateShareModal), 350);
          } else {
            throw new Error('Bad response');
          }
        }).catch(() => {
          if (templateShareStatus) {
            templateShareStatus.textContent = 'Could not update template shares.';
            templateShareStatus.classList.add('is-error');
          }
        });
      });
    }
  }

  document.querySelectorAll('[data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      closeModal(btn.closest('[data-modal]'));
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php';
