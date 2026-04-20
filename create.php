<?php
require __DIR__ . '/private/config.php';
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Umfrage anlegen</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
<main class="page stack">
    <a class="button button-secondary" href="index.php">Zur Uebersicht</a>

    <section class="card reveal">
        <h1>Neue Umfrage anlegen</h1>
        <p class="muted">Erstelle in wenigen Schritten eine neue Abstimmung.</p>

        <form action="create_survey.php" method="post">
            <div class="field">
                <label for="question">Frage oder Titel der Umfrage</label>
                <input type="text" id="question" name="question" required maxlength="255" placeholder="Welche Option bevorzugst du?">
            </div>

            <div class="field">
                <label for="choices">Antwortoptionen (eine pro Zeile)</label>
                <textarea id="choices" name="choices" rows="6" required placeholder="Option A&#10;Option B&#10;Option C"></textarea>
                <p class="muted">Mindestens zwei Optionen, leere Zeilen werden ignoriert.</p>
            </div>

            <div class="field">
                <label for="expected_votes">Erwartete Anzahl an Stimmen</label>
                <input type="number" id="expected_votes" name="expected_votes" min="1" required>
            </div>

            <div class="field">
                <label for="email">Benachrichtigungs-E-Mail</label>
                <input type="email" id="email" name="email" required placeholder="name@beispiel.de">
            </div>

            <div class="actions sticky-mobile">
                <button type="submit">Umfrage erstellen</button>
            </div>
        </form>
    </section>
</main>
</body>

</html>
