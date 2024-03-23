<?php
$deckDirectory = __DIR__ . '/decks';
$decks = [];

if ($handle = opendir($deckDirectory)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry !== "." && $entry !== ".." && strtolower(substr($entry, strrpos($entry, '.') + 1)) == 'json') {
            $content = file_get_contents($deckDirectory . '/' . $entry);
            $json = json_decode($content, true);
            $decks[] = [
                'filename' => $entry,
                'name' => $json['header']['name'],
                'description' => $json['header']['description']
            ];
        }
    }
    closedir($handle);
}
?>

<form action="rehearse.php" method="post">
    <fieldset>
        <legend>Select Decks for Rehearsal</legend>
        <?php
        // Assuming $decks array contains the decks data
        foreach ($decks as $deck) {
            echo '<div>';
            echo '<input type="checkbox" name="decks[]" value="' . htmlspecialchars($deck['filename']) . '">';
            echo '<label>' . htmlspecialchars($deck['name']) . ': ' . htmlspecialchars($deck['description']) . '</label>';
            echo '</div>';
        }
        ?>
    </fieldset>
    <input type="submit" value="Start Rehearsal">
</form>
