<?php
require __DIR__ . '/private/config.php';

$stmt = $pdo->query("
    SELECT
        s.id,
        s.public_id,
        s.question,
        s.expected_votes,
        s.created_at,
        s.expires_at,
        COUNT(v.id) AS total_votes,
        CASE
            WHEN COUNT(v.id) >= s.expected_votes OR s.expires_at <= NOW() THEN 1
            ELSE 0
        END AS is_closed
    FROM surveys s
    LEFT JOIN votes v ON v.survey_id = s.id
    GROUP BY s.id, s.public_id, s.question, s.expected_votes, s.created_at, s.expires_at
    ORDER BY s.created_at DESC
");

$surveys = $stmt->fetchAll();

function formatRemainingTime(string $expiresAtRaw): string
{
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($expiresAtRaw);

    if ($now >= $expiresAt) {
        return 'abgelaufen';
    }

    $diff = $now->diff($expiresAt);

    if ($diff->days > 0) {
        return sprintf('%d Tage, %d Std.', $diff->days, $diff->h);
    }

    if ($diff->h > 0) {
        return sprintf('%d Std., %d Min.', $diff->h, $diff->i);
    }

    return sprintf('%d Min.', max(1, $diff->i));
}

function statusBadgeClass(array $survey): string
{
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($survey['expires_at']);
    $isExpired = $now >= $expiresAt;

    if ($isExpired) {
        return 'badge-expired';
    }

    if ((int) $survey['is_closed'] === 1) {
        return 'badge-closed';
    }

    return 'badge-open';
}

function statusText(array $survey): string
{
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($survey['expires_at']);
    $isExpired = $now >= $expiresAt;

    if ($isExpired) {
        return 'abgelaufen';
    }

    if ((int) $survey['is_closed'] === 1) {
        return 'geschlossen';
    }

    return 'offen (' . formatRemainingTime($survey['expires_at']) . ')';
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Umfragen</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <main class="page">
        <header class="topbar reveal">
            <div>
                <h1>Umfragen</h1>
                <p class="hero-intro">Oeffentliche Uebersicht aller Abstimmungen</p>
                <span class="inline-chip">Mobil optimiert</span>
            </div>
            <div class="actions">
                <a class="button button-primary" href="create.php">Neue Umfrage</a>
                <a class="button button-secondary" href="admin.php">Admin</a>
            </div>
        </header>

        <?php if (!$surveys): ?>
            <section class="card reveal reveal-delay-1">
                <p>Aktuell gibt es keine Umfragen.</p>
            </section>
        <?php else: ?>
            <section class="grid-cards">
                <?php foreach ($surveys as $idx => $survey): ?>
                    <article class="card survey-card reveal reveal-delay-<?php echo (($idx % 3) + 1); ?>">
                        <h2><?php echo htmlspecialchars($survey['question']); ?></h2>
                        <p class="muted">Gestartet: <?php echo htmlspecialchars($survey['created_at']); ?></p>

                        <div class="survey-meta">
                            <span class="badge <?php echo statusBadgeClass($survey); ?>">
                                <?php echo htmlspecialchars(statusText($survey)); ?>
                            </span>
                            <span class="badge"><?php echo (int) $survey['total_votes']; ?> / <?php echo (int) $survey['expected_votes']; ?> Stimmen</span>
                        </div>

                        <a class="button button-primary" href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">Zur Umfrage</a>
                    </article>
                <?php endforeach; ?>
            </section>

            <table class="table-desktop" aria-label="Umfragen Desktop Ansicht">
                <thead>
                    <tr>
                        <th>Umfrage</th>
                        <th>Stimmen</th>
                        <th>Ziel</th>
                        <th>Status</th>
                        <th>Oeffnen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($surveys as $survey): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($survey['question']); ?>
                                <div class="muted">gestartet: <?php echo htmlspecialchars($survey['created_at']); ?></div>
                            </td>
                            <td><?php echo (int) $survey['total_votes']; ?></td>
                            <td><?php echo (int) $survey['expected_votes']; ?></td>
                            <td><?php echo htmlspecialchars(statusText($survey)); ?></td>
                            <td>
                                <a href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">Zur Umfrage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>

</html>
