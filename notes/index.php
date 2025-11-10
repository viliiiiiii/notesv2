<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();

$meId = (int)(current_user()['id'] ?? 0);
$pdo  = get_pdo();

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

$noteIds = array_map(static fn(array $row) => (int)($row['id'] ?? 0), $rows);
$noteIds = array_values(array_filter($noteIds));
$tagsMap = $noteIds ? notes_fetch_tags_for_notes($noteIds) : [];

foreach ($rows as &$row) {
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
    $row['_tags']        = $tagsMap[$row['id']] ?? [];
}
unset($row);

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
$heroSubtitle = $totalNotes
    ? sprintf(
        'Tracking %s notes across %s statuses — %s%% owned by you and %s%% shared.',
        number_format($totalNotes),
        number_format(count($statuses)),
        number_format($percentOwned),
        number_format($percentShared)
    )
    : 'Create your first note to capture ideas, decisions, and walkthrough observations.';
$lastUpdateHint = $lastUpdatedRelative !== ''
    ? 'Updated ' . $lastUpdatedRelative
    : 'Waiting for first update';

function toggle_view_url(string $targetView): string {
    $q = $_GET;
    $q['view'] = $targetView;
    return 'index.php?' . http_build_query($q);
}

$title = 'Notes';
include __DIR__ . '/../includes/header.php';
?>

<section class="notes-hero card card--surface">
  <div class="notes-hero__headline">
    <div>
      <p class="notes-hero__eyebrow">Workspace notes</p>
      <h1>Team knowledge hub</h1>
      <p class="notes-hero__subtitle"><?= sanitize($heroSubtitle); ?></p>
      <div class="notes-hero__actions">
        <a class="btn primary" href="new.php">New note</a>
        <a class="btn secondary" href="<?= $view === 'board' ? toggle_view_url('table') : toggle_view_url('board'); ?>">
          Switch to <?= $view === 'board' ? 'list' : 'board'; ?> view
        </a>
      </div>
    </div>
    <div class="notes-hero__meta">
      <div class="notes-hero__stamp">
        <span class="notes-hero__stamp-label">Last updated</span>
        <span class="notes-hero__stamp-value"><?= $lastUpdatedRelative ? sanitize($lastUpdatedRelative) : '—'; ?></span>
        <?php if ($lastUpdatedAbsolute): ?>
          <span class="notes-hero__stamp-hint"><?= sanitize($lastUpdatedAbsolute); ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="notes-hero__metrics">
    <div class="notes-metric">
      <span class="notes-metric__label">Active</span>
      <span class="notes-metric__value" data-count-target="<?= (int)$activeCount; ?>"><?= number_format($activeCount); ?></span>
      <p class="notes-metric__hint">Notes not archived.</p>
    </div>
    <div class="notes-metric">
      <span class="notes-metric__label">Shared</span>
      <span class="notes-metric__value" data-count-target="<?= (int)$sharedCount; ?>"><?= number_format($sharedCount); ?></span>
      <p class="notes-metric__hint"><?= number_format($percentShared); ?>% collaborative.</p>
    </div>
    <div class="notes-metric">
      <span class="notes-metric__label">Completed</span>
      <span class="notes-metric__value" data-count-target="<?= (int)$completedCount; ?>"><?= number_format($completedCount); ?></span>
      <p class="notes-metric__hint">Marked done in the board.</p>
    </div>
    <div class="notes-metric">
      <span class="notes-metric__label">Media</span>
      <span class="notes-metric__value" data-count-target="<?= (int)$photoRichCount; ?>" data-count-decimals="0"><?= number_format($photoRichCount); ?></span>
      <p class="notes-metric__hint">Avg <?= number_format($avgPhotosRounded, 1); ?> photos attached.</p>
    </div>
  </div>
</section>

<section class="card card--surface notes-controls">
  <form method="get" class="notes-filter" action="index.php" autocomplete="off">
    <input type="hidden" name="view" value="<?= sanitize($view); ?>">
    <div class="notes-filter__grid">
      <label class="notes-field">
        <span class="notes-field__label">Search</span>
        <input type="search" name="q" value="<?= sanitize($search); ?>" placeholder="Title, text, or tag" data-live-search>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">From</span>
        <input type="date" name="from" value="<?= sanitize($from); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">To</span>
        <input type="date" name="to" value="<?= sanitize($to); ?>" <?= $hasNoteDate ? '' : 'disabled'; ?>>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">Status</span>
        <select name="status" <?= $hasMetaTbl ? '' : 'disabled'; ?>>
          <option value="">Any status</option>
          <?php foreach ($statuses as $slug => $label): ?>
            <option value="<?= sanitize($slug); ?>" <?= $statusFilter === $slug ? 'selected' : ''; ?>><?= sanitize($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="notes-field">
        <span class="notes-field__label">Tag</span>
        <select name="tag" <?= $hasTagTbl ? '' : 'disabled'; ?>>
          <option value="">Any tag</option>
          <?php foreach ($tagOptions as $tag): ?>
            <?php $label = $tag['label'] ?? ''; ?>
            <option value="<?= sanitize($label); ?>" <?= strcasecmp($tagFilter, $label) === 0 ? 'selected' : ''; ?>><?= sanitize($label); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="notes-filter__actions">
      <button class="btn" type="submit">Apply filters</button>
      <a class="btn secondary" href="index.php?view=<?= $view === 'board' ? 'board' : 'table'; ?>">Clear</a>
      <button class="btn ghost" type="button" data-reset-filters>Reset quick filters</button>
    </div>
  </form>
  <div class="notes-toolbar">
    <div class="notes-quick-filters" role="group" aria-label="Quick filters">
      <button type="button" class="chip is-active" data-filter-button="all" aria-pressed="true">All notes</button>
      <button type="button" class="chip" data-filter-button="mine" aria-pressed="false">Mine</button>
      <button type="button" class="chip" data-filter-button="shared" aria-pressed="false">Shared</button>
      <button type="button" class="chip" data-filter-button="photos" aria-pressed="false">With photos</button>
      <button type="button" class="chip" data-filter-button="replies" aria-pressed="false">With replies</button>
    </div>
    <div class="notes-view-toggle" role="group" aria-label="View mode">
      <a class="notes-view-toggle__link<?= $view === 'table' ? ' is-active' : ''; ?> js-toggle-view" href="<?= toggle_view_url('table'); ?>">List view</a>
      <a class="notes-view-toggle__link<?= $view === 'board' ? ' is-active' : ''; ?> js-toggle-view" href="<?= toggle_view_url('board'); ?>">Board view</a>
    </div>
  </div>
</section>

<section class="card card--surface notes-content">
  <?php if (!$rows): ?>
    <div class="notes-empty notes-empty--initial">
      <div class="notes-empty__icon" aria-hidden="true">🧭</div>
      <h2>No notes yet</h2>
      <p class="muted">Design your first Notion-style document to organize site knowledge and punch-list planning.</p>
      <a class="btn primary" href="new.php">Create a note</a>
    </div>
  <?php else: ?>
    <div class="notes-empty" data-empty aria-hidden="true">
      <div class="notes-empty__icon" aria-hidden="true">🔍</div>
      <h3>No notes match your filters</h3>
      <p class="muted">Try clearing the search, selecting a different status, or resetting quick filters.</p>
      <button class="btn secondary" type="button" data-reset-filters>Reset filters</button>
    </div>

    <?php if ($view === 'board'): ?>
      <div class="note-board" data-note-collection>
        <?php foreach ($statuses as $slug => $label):
          $columnNotes = $statusColumns[$slug] ?? [];
        ?>
          <section class="note-board__column" data-status="<?= sanitize($slug); ?>">
            <header class="note-board__header">
              <span class="badge <?= sanitize(notes_status_badge_class($slug)); ?>"><?= sanitize($label); ?></span>
              <span class="note-board__count"><?= count($columnNotes); ?></span>
            </header>
            <div class="note-board__list">
              <?php if (!$columnNotes): ?>
                <p class="note-board__empty muted">No notes in this status.</p>
              <?php else: ?>
                <?php foreach ($columnNotes as $n):
                  $noteId      = (int)($n['id'] ?? 0);
                  $noteTitle   = (string)($n['title'] ?? 'Untitled');
                  $noteBody    = (string)($n['body'] ?? '');
                  $tagsForNote = $n['_tags'] ?? [];
                  $tagLabels   = array_map(static fn($tag) => (string)($tag['label'] ?? ''), $tagsForNote);
                  $tagSearch   = implode(' ', $tagLabels);
                  $searchIdx   = notes__search_index($noteTitle . ' ' . $tagSearch, $noteBody);
                  $isOwner     = !empty($n['is_owner']);
                  $isShared    = !empty($n['is_shared']) && !$isOwner;
                  $pc          = (int)($n['photo_count'] ?? 0);
                  $cc          = (int)($n['comment_count'] ?? 0);
                  $properties  = $n['_properties'] ?? notes_default_properties();
                  $dueRaw      = trim((string)($properties['due_date'] ?? ''));
                  $dueDisplay  = notes__format_date($dueRaw);
                  $dueOverdue  = false;
                  if ($dueRaw !== '') {
                    try { $dueOverdue = (new DateTimeImmutable($dueRaw)) < new DateTimeImmutable('today'); }
                    catch (Throwable $e) { $dueOverdue = false; }
                  }
                  $priority    = trim((string)($properties['priority'] ?? ''));
                  $priorityBadge = $priority !== '' ? notes_priority_badge_class($priority) : '';
                  $project     = trim((string)($properties['project'] ?? ''));
                  $location    = trim((string)($properties['location'] ?? ''));
                  $statusSlug  = $n['_status'] ?? NOTES_DEFAULT_STATUS;
                  $statusLabel = notes_status_label($statusSlug);
                  $statusBadge = notes_status_badge_class($statusSlug);
                  $iconDisplay = trim((string)($n['_meta']['icon'] ?? ''));
                  if ($iconDisplay === '') { $iconDisplay = '🗒️'; }
                  $tagData = implode(',', array_filter(array_map(static fn($label) => strtolower($label), $tagLabels)));
                ?>
                  <article class="note-card"
                           data-note
                           data-owned="<?= $isOwner ? '1' : '0'; ?>"
                           data-shared="<?= $isShared ? '1' : '0'; ?>"
                           data-photos="<?= $pc; ?>"
                           data-comments="<?= $cc; ?>"
                           data-search="<?= sanitize($searchIdx); ?>"
                           data-status="<?= sanitize($statusSlug); ?>"
                           data-tags="<?= sanitize($tagData); ?>">
                    <header class="note-card__header">
                      <div class="note-card__icon"><?= sanitize($iconDisplay); ?></div>
                      <div class="note-card__titles">
                        <a href="view.php?id=<?= $noteId; ?>" class="note-card__title"><?= sanitize($noteTitle); ?></a>
                        <div class="note-card__subtitle">
                          <?php if ($project !== ''): ?><span><?= sanitize($project); ?></span><?php endif; ?>
                          <?php if ($location !== ''): ?><span><?= sanitize($location); ?></span><?php endif; ?>
                        </div>
                      </div>
                    </header>
                    <?php if ($tagsForNote): ?>
                      <div class="note-card__tags">
                        <?php foreach ($tagsForNote as $tag): ?>
                          <span class="note-tag" style="--tag-color: <?= sanitize($tag['color'] ?? notes_random_tag_color()); ?>;"><?= sanitize($tag['label'] ?? ''); ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <div class="note-card__status">
                      <span class="badge <?= sanitize($statusBadge); ?>"><?= sanitize($statusLabel); ?></span>
                      <span class="note-card__counts">📷 <?= $pc; ?> · 💬 <?= $cc; ?></span>
                    </div>
                    <ul class="note-card__meta">
                      <li><span>Due</span><strong class="<?= $dueOverdue ? 'is-overdue' : ''; ?>"><?= $dueDisplay !== '' ? sanitize($dueDisplay) : '—'; ?></strong></li>
                      <li><span>Priority</span>
                        <?php if ($priority !== ''): ?>
                          <span class="badge <?= sanitize($priorityBadge); ?>"><?= sanitize($priority); ?></span>
                        <?php else: ?>
                          <span class="muted">—</span>
                        <?php endif; ?>
                      </li>
                    </ul>
                    <footer class="note-card__footer">
                      <a class="btn tiny" href="view.php?id=<?= $noteId; ?>">Open</a>
                      <?php if (notes_can_edit($n)): ?>
                        <a class="btn tiny" href="edit.php?id=<?= $noteId; ?>">Edit</a>
                      <?php endif; ?>
                    </footer>
                  </article>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="notes-table-wrapper">
        <table class="table notes-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Status</th>
              <th>Due</th>
              <th>Priority</th>
              <th>Photos</th>
              <th>Replies</th>
              <th>Updated</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody data-note-collection>
            <?php foreach ($rows as $n):
              $noteId      = (int)($n['id'] ?? 0);
              $noteTitle   = (string)($n['title'] ?? 'Untitled');
              $noteBody    = (string)($n['body'] ?? '');
              $tagsForNote = $n['_tags'] ?? [];
              $tagLabels   = array_map(static fn($tag) => (string)($tag['label'] ?? ''), $tagsForNote);
              $tagSearch   = implode(' ', $tagLabels);
              $searchIdx   = notes__search_index($noteTitle . ' ' . $tagSearch, $noteBody);
              $isOwner     = !empty($n['is_owner']);
              $isShared    = !empty($n['is_shared']) && !$isOwner;
              $pc          = (int)($n['photo_count'] ?? 0);
              $cc          = (int)($n['comment_count'] ?? 0);
              $properties  = $n['_properties'] ?? notes_default_properties();
              $dueRaw      = trim((string)($properties['due_date'] ?? ''));
              $dueDisplay  = notes__format_date($dueRaw);
              $dueOverdue  = false;
              if ($dueRaw !== '') {
                  try { $dueOverdue = (new DateTimeImmutable($dueRaw)) < new DateTimeImmutable('today'); }
                  catch (Throwable $e) { $dueOverdue = false; }
              }
              $priority    = trim((string)($properties['priority'] ?? ''));
              $priorityBadge = $priority !== '' ? notes_priority_badge_class($priority) : '';
              $project     = trim((string)($properties['project'] ?? ''));
              $location    = trim((string)($properties['location'] ?? ''));
              $statusSlug  = $n['_status'] ?? NOTES_DEFAULT_STATUS;
              $statusLabel = notes_status_label($statusSlug);
              $statusBadge = notes_status_badge_class($statusSlug);
              $iconDisplay = trim((string)($n['_meta']['icon'] ?? ''));
              if ($iconDisplay === '') { $iconDisplay = '🗒️'; }
              $noteRelative = notes__relative_time($n['updated_at'] ?? $n['created_at'] ?? $n['note_date'] ?? null);
              $excerpt      = notes__excerpt($noteBody, 160);
              $noteDate     = $n['note_date'] ?? '';
              $noteDateDisplay = notes__format_date($noteDate);
              $tagData = implode(',', array_filter(array_map(static fn($label) => strtolower($label), $tagLabels)));
            ?>
              <tr class="notes-row<?= $isShared ? ' is-shared' : ''; ?>"
                  data-note
                  data-owned="<?= $isOwner ? '1' : '0'; ?>"
                  data-shared="<?= $isShared ? '1' : '0'; ?>"
                  data-photos="<?= $pc; ?>"
                  data-comments="<?= $cc; ?>"
                  data-search="<?= sanitize($searchIdx); ?>"
                  data-status="<?= sanitize($statusSlug); ?>"
                  data-tags="<?= sanitize($tagData); ?>">
                <td class="notes-table__title">
                  <div class="notes-table__title-main">
                    <span class="notes-table__icon"><?= sanitize($iconDisplay); ?></span>
                    <div>
                      <a href="view.php?id=<?= $noteId; ?>"><?= sanitize($noteTitle); ?></a>
                      <div class="notes-table__meta">
                        <?php if ($noteDateDisplay !== ''): ?><span><?= sanitize($noteDateDisplay); ?></span><?php endif; ?>
                        <?php if ($project !== ''): ?><span><?= sanitize($project); ?></span><?php endif; ?>
                        <?php if ($location !== ''): ?><span><?= sanitize($location); ?></span><?php endif; ?>
                      </div>
                      <?php if ($tagsForNote): ?>
                        <div class="notes-table__tags">
                          <?php foreach ($tagsForNote as $tag): ?>
                            <span class="note-tag" style="--tag-color: <?= sanitize($tag['color'] ?? notes_random_tag_color()); ?>;"><?= sanitize($tag['label'] ?? ''); ?></span>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($excerpt !== ''): ?>
                    <div class="notes-snippet"><?= sanitize($excerpt); ?></div>
                  <?php endif; ?>
                </td>
                <td data-label="Status"><span class="badge <?= sanitize($statusBadge); ?>"><?= sanitize($statusLabel); ?></span></td>
                <td data-label="Due"><span class="<?= $dueOverdue ? 'is-overdue' : ''; ?>"><?= $dueDisplay !== '' ? sanitize($dueDisplay) : '—'; ?></span></td>
                <td data-label="Priority">
                  <?php if ($priority !== ''): ?>
                    <span class="badge <?= sanitize($priorityBadge); ?>"><?= sanitize($priority); ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td data-label="Photos"><span class="notes-count-chip<?= $pc ? ' has-value' : ''; ?>">📷 <?= $pc; ?></span></td>
                <td data-label="Replies"><span class="notes-count-chip<?= $cc ? ' has-value' : ''; ?>">💬 <?= $cc; ?></span></td>
                <td data-label="Updated"><?= $noteRelative ? sanitize($noteRelative) : '—'; ?></td>
                <td class="text-right">
                  <div class="notes-row__actions">
                    <a class="btn small" href="view.php?id=<?= $noteId; ?>">View</a>
                    <?php if (notes_can_edit($n)): ?>
                      <a class="btn small" href="edit.php?id=<?= $noteId; ?>">Edit</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<style>
.notes-hero{ position:relative; padding:1.6rem; border-radius:1.2rem; background:#fff; border:1px solid #e2e8f0; box-shadow:0 10px 22px rgba(15,23,42,.05); display:grid; gap:1.25rem; }
.notes-hero__headline{ display:flex; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; align-items:flex-start; }
.notes-hero__eyebrow{ font-size:.75rem; text-transform:uppercase; letter-spacing:.12em; color:#4338ca; margin:0 0 .5rem; }
.notes-hero__subtitle{ margin:0; max-width:38rem; color:#475569; line-height:1.5; }
.notes-hero__actions{ display:flex; gap:.55rem; flex-wrap:wrap; }
.notes-hero__meta{ display:flex; align-items:flex-end; }
.notes-hero__stamp{ background:#f8fafc; border-radius:.9rem; padding:.9rem 1.1rem; border:1px solid #e2e8f0; box-shadow:0 8px 18px rgba(15,23,42,.07); display:flex; flex-direction:column; gap:.25rem; }
.notes-hero__stamp-label{ font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#475569; }
.notes-hero__stamp-value{ font-size:1.6rem; font-weight:600; color:#0f172a; }
.notes-hero__stamp-hint{ font-size:.85rem; color:#6366f1; }
.notes-hero__metrics{ display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); }
.notes-metric{ background:#fff; border-radius:.95rem; padding:1rem; box-shadow:0 8px 18px rgba(15,23,42,.05); border:1px solid #e2e8f0; display:flex; flex-direction:column; gap:.35rem; }
.notes-metric__label{ font-size:.78rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.notes-metric__value{ font-size:1.75rem; font-weight:600; color:#1e293b; }
.notes-metric__hint{ font-size:.85rem; color:#94a3b8; margin:0; }

.notes-controls{ margin-top:1.25rem; display:flex; flex-direction:column; gap:1.25rem; }
.notes-filter{ display:flex; flex-direction:column; gap:1rem; }
.notes-filter__grid{ display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
.notes-field{ display:flex; flex-direction:column; gap:.35rem; }
.notes-field__label{ font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.notes-field input, .notes-field select{ border:1px solid #d0d7e2; border-radius:.75rem; padding:.6rem .8rem; font-size:1rem; background:#fff; }
.notes-filter__actions{ display:flex; gap:.75rem; flex-wrap:wrap; }
.notes-toolbar{ display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:center; }
.notes-quick-filters{ display:flex; gap:.5rem; flex-wrap:wrap; }
.chip{ border:1px solid #d0d7e2; background:#f8fafc; color:#1e293b; border-radius:999px; padding:.4rem .9rem; font-size:.82rem; cursor:pointer; transition:all .2s ease; }
.chip.is-active, .chip[aria-pressed="true"]{ background:#6366f1; color:#fff; border-color:#6366f1; box-shadow:0 8px 18px rgba(99,102,241,.3); }
.notes-view-toggle{ display:flex; gap:.5rem; border:1px solid #d0d7e2; border-radius:999px; padding:.25rem; background:#f8fafc; }
.notes-view-toggle__link{ padding:.4rem 1rem; border-radius:999px; text-decoration:none; color:#475569; font-weight:500; }
.notes-view-toggle__link.is-active{ background:#0ea5e9; color:#fff; box-shadow:0 8px 18px rgba(14,165,233,.25); }

.notes-content{ margin-top:1.25rem; position:relative; }
.notes-empty{ display:none; flex-direction:column; align-items:center; justify-content:center; gap:.75rem; padding:2.25rem 1rem; text-align:center; border:1px dashed #d0d7e2; border-radius:1.25rem; background:#fff; }
.notes-empty.is-visible{ display:flex; }
.notes-empty--initial{ display:flex; }
.notes-empty__icon{ font-size:2rem; }

.note-board{ display:grid; gap:.9rem; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
.note-board__column{ display:grid; gap:.6rem; background:#fff; border-radius:1rem; padding:.85rem; border:1px solid #e2e8f0; box-shadow:0 5px 14px rgba(15,23,42,.045); }
.note-board__header{ display:flex; justify-content:space-between; align-items:center; }
.note-board__count{ font-size:.85rem; color:#475569; }
.note-board__list{ display:grid; gap:.6rem; }
.note-board__empty{ margin:0; font-size:.9rem; }

.note-card{ background:#fff; border-radius:.9rem; border:1px solid #e2e8f0; padding:.85rem .95rem; box-shadow:0 6px 16px rgba(15,23,42,.05); display:grid; gap:.65rem; }
.note-card__header{ display:flex; gap:.7rem; align-items:center; }
.note-card__icon{ width:36px; height:36px; border-radius:10px; background:#f1f5f9; border:1px solid #e2e8f0; display:grid; place-items:center; font-size:1rem; }
.note-card__title{ font-weight:600; color:#0f172a; text-decoration:none; }
.note-card__title:hover{ text-decoration:underline; }
.note-card__subtitle{ display:flex; gap:.5rem; font-size:.85rem; color:#64748b; flex-wrap:wrap; }
.note-card__tags{ display:flex; gap:.4rem; flex-wrap:wrap; }
.note-card__status{ display:flex; justify-content:space-between; align-items:center; gap:.5rem; font-size:.85rem; }
.note-card__meta{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; margin:0; padding:0; list-style:none; font-size:.85rem; color:#475569; }
.note-card__meta span{ display:block; color:#94a3b8; font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; }
.note-card__meta strong{ font-size:.95rem; color:#0f172a; }
.note-card__footer{ display:flex; gap:.5rem; justify-content:flex-end; }

.notes-table-wrapper{ overflow-x:auto; }
.notes-table thead th{ text-transform:uppercase; font-size:.8rem; letter-spacing:.08em; color:#64748b; }
.notes-table__title{ min-width:260px; }
.notes-table__title-main{ display:flex; gap:.5rem; align-items:flex-start; }
.notes-table__icon{ width:34px; height:34px; border-radius:9px; background:#f1f5f9; border:1px solid #e2e8f0; display:grid; place-items:center; font-size:1rem; }
.notes-table__meta{ display:flex; gap:.45rem; flex-wrap:wrap; font-size:.8rem; color:#94a3b8; margin:.25rem 0; }
.notes-table__tags{ display:flex; gap:.35rem; flex-wrap:wrap; }
.notes-row{ transition:background .2s ease, box-shadow .2s ease; }
.notes-row:hover{ background:#f8fafc; box-shadow:inset 0 0 0 1px #e2e8f0; }
.notes-row.is-shared{ border-left:4px solid rgba(99,102,241,.5); }
.notes-snippet{ margin-top:.35rem; font-size:.9rem; color:#475569; line-height:1.4; }
.notes-count-chip{ display:inline-flex; align-items:center; gap:.25rem; background:rgba(226,232,240,.7); border-radius:999px; padding:.3rem .75rem; font-size:.85rem; }
.notes-count-chip.has-value{ background:#e0f2fe; color:#0f172a; }
.notes-row__actions{ display:flex; gap:.4rem; justify-content:flex-end; }

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

.note-tag{ display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:999px; background:rgba(148,163,184,.15); color:#0f172a; font-size:.8rem; position:relative; }
.note-tag::before{ content:''; width:8px; height:8px; border-radius:50%; background:var(--tag-color,#6366f1); }

.muted{ color:#64748b; }
.is-overdue{ color:#b91c1c; font-weight:600; }

@media (max-width:900px){
  .notes-hero{ padding:2rem; }
  .notes-hero__headline{ flex-direction:column; align-items:flex-start; }
  .notes-toolbar{ flex-direction:column; align-items:flex-start; }
  .notes-view-toggle{ align-self:stretch; justify-content:space-between; }
}
</style>

<script>
(() => {
  const CURRENT = '<?= $view === 'board' ? 'board' : 'table'; ?>';
  try { localStorage.setItem('notes_view', CURRENT); } catch (e) {}

  document.addEventListener('click', (e) => {
    const link = e.target.closest('.js-toggle-view');
    if (!link) return;
    try {
      const url = new URL(link.href, location.href);
      let next = url.searchParams.get('view') || 'table';
      if (next === 'sticky') { next = 'board'; }
      localStorage.setItem('notes_view', next);
    } catch (err) {}
  });

  (function applyInitialPreference() {
    const params = new URLSearchParams(location.search);
    if (params.has('view')) return;
    try {
      let pref = localStorage.getItem('notes_view');
      if (pref === 'sticky') { pref = 'board'; }
      if (pref && (pref === 'table' || pref === 'board') && pref !== CURRENT) {
        const u = new URL(location.href);
        u.searchParams.set('view', pref);
        location.replace(u.toString());
      }
    } catch (err) {}
  })();

  window.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('[data-live-search]');
    const noteNodes = Array.from(document.querySelectorAll('[data-note]'));
    const quickButtons = Array.from(document.querySelectorAll('[data-filter-button]'));
    const emptyState = document.querySelector('[data-empty]');
    const resetButtons = Array.from(document.querySelectorAll('[data-reset-filters]'));
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let activeFilter = 'all';

    function updateButtonStates() {
      quickButtons.forEach((btn) => {
        const value = btn.getAttribute('data-filter-button') || 'all';
        const isActive = activeFilter !== 'all' && value === activeFilter;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      const allButton = quickButtons.find((btn) => (btn.getAttribute('data-filter-button') || 'all') === 'all');
      if (allButton) {
        const highlight = activeFilter === 'all';
        allButton.classList.toggle('is-active', highlight);
        allButton.setAttribute('aria-pressed', highlight ? 'true' : 'false');
      }
    }

    function matchesQuickFilter(node) {
      switch (activeFilter) {
        case 'mine':
          return node.dataset.owned === '1';
        case 'shared':
          return node.dataset.shared === '1';
        case 'photos':
          return Number(node.dataset.photos || 0) > 0;
        case 'replies':
          return Number(node.dataset.comments || 0) > 0;
        default:
          return true;
      }
    }

    function applyFilters() {
      const term = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
      let visible = 0;

      noteNodes.forEach((node) => {
        const haystack = (node.dataset.search || '').toLowerCase();
        const matchesSearch = term === '' || haystack.includes(term);
        const matchesFilter = matchesQuickFilter(node);
        const shouldShow = matchesSearch && matchesFilter;
        node.classList.toggle('is-hidden', !shouldShow);
        if (shouldShow) {
          visible++;
        }
      });

      if (emptyState) {
        emptyState.classList.toggle('is-visible', visible === 0);
        emptyState.setAttribute('aria-hidden', visible === 0 ? 'false' : 'true');
      }
    }

    if (searchInput) {
      searchInput.addEventListener('input', applyFilters);
      searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          searchInput.value = '';
          applyFilters();
        }
      });
    }

    quickButtons.forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        const value = btn.getAttribute('data-filter-button') || 'all';
        activeFilter = activeFilter === value ? 'all' : value;
        updateButtonStates();
        applyFilters();
      });
    });

    resetButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        activeFilter = 'all';
        if (searchInput) {
          searchInput.value = '';
        }
        updateButtonStates();
        applyFilters();
      });
    });

    updateButtonStates();
    applyFilters();

    if (!prefersReducedMotion) {
      const counters = Array.from(document.querySelectorAll('[data-count-target]'));
      counters.forEach((el) => {
        const target = Number(el.getAttribute('data-count-target') || '0');
        const decimals = Number(el.getAttribute('data-count-decimals') || '0');
        if (!Number.isFinite(target)) {
          return;
        }
        const start = performance.now();
        const duration = 700;
        const formatter = new Intl.NumberFormat(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

        function step(now) {
          const progress = Math.min(1, (now - start) / duration);
          const value = target * progress;
          el.textContent = formatter.format(value);
          if (progress < 1) {
            requestAnimationFrame(step);
          } else {
            el.textContent = formatter.format(target);
          }
        }

        el.textContent = formatter.format(0);
        requestAnimationFrame(step);
      });
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php';
