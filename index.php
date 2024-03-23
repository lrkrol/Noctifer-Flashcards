<?php


$deckDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'decks';


if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['decks'])) {
    // POST data indicates decks have been selected; switching to rehearse mode
    $selectedDecks = json_encode($_POST['decks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    $rehearseScript = rehearse($deckDirectory, $_POST['decks']);
    $rehearseHTML = <<<EOL
        <div id="probe"></div>
        <div id="answer"></div>
        <div id="responses">
            <div id="show">Show answer</div>
            <div id="again">Again</div>
            <div id="hard">Hard</div>
            <div id="good">Good</div>
            <div id="easy">Easy</div>
        </div>

EOL;
} else {
    // showing list of available decks
    $deckHTML = listDecks($deckDirectory);
}


function listDecks($deckDirectory) {
    $decks = [];
    
    // reading deck files
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
    
    // producing html listing decks
    $deckHTML = '<form action="' . basename(__FILE__) . '" method="POST">' . PHP_EOL . '<fieldset>' . PHP_EOL . '    <legend>Select Decks for Rehearsal</legend>'. PHP_EOL;
    foreach ($decks as $deck) {
        $deckHTML = $deckHTML . '    <div class="deckselector">'. PHP_EOL;
        $deckHTML = $deckHTML . '        <input type="checkbox" name="decks[]" value="' . htmlspecialchars($deck['filename']) . '">'. PHP_EOL;
        $deckHTML = $deckHTML . '        <label>' . htmlspecialchars($deck['name']) . ': ' . htmlspecialchars($deck['description']) . '</label>'. PHP_EOL;
        $deckHTML = $deckHTML . '    </div>'. PHP_EOL;
    }
    $deckHTML = $deckHTML . '</fieldset>' . PHP_EOL . '<input type="submit" value="Start Rehearsal">' . PHP_EOL . '</form>'. PHP_EOL;
    
    return $deckHTML;
}


function rehearse($deckDirectory, $selectedDecks) {
    $deckData = [];
    
    // reading selected deck contents
    foreach ($selectedDecks as $deckFilename) {
        $filePath = $deckDirectory . DIRECTORY_SEPARATOR . $deckFilename;
        
        if (file_exists($filePath)) {
            $deckContent = json_decode(file_get_contents($filePath), true);
            $allowDirectionChange = $deckContent['header']['allowDirectionChange'] ?? false; // default to false if not specified

            foreach ($deckContent['cards'] as &$card) {
                $card['id'] = $deckFilename . '#' . $card['id'];
                $card['deckFilename'] = $deckFilename;
                $card['allowDirectionChange'] = $allowDirectionChange;
                if (!empty($card['audio'])) {
                    $card['audio'] = str_replace(DIRECTORY_SEPARATOR, '/', $deckDirectory . $card['audio']);
                }
            }
            unset($card);

            // flattening all cards into a single array
            $deckData = array_merge($deckData, $deckContent['cards']);
        }
    }

    $deckData = json_encode($deckData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    
    // producing script to load data into database upon page load, as needed for rehearsal
    $rehearseScript = <<<EOT
    document.addEventListener("DOMContentLoaded", function() {
        initIndexedDB().then(() => {
            const deckData = JSON.parse('$deckData');
            loadCardsIntoDB(deckData).then(() => {
                selectNextCard(selectedDecks).then(cardToShow => {displayCard(cardToShow);})
            });
        });
    });
    
EOT;

    return $rehearseScript;
}


?>


<!DOCTYPE html>
<html lang="en">
<head>
    <script>
    let db;
    let currentCard = null;
        
    // reproducing rehearsal code here in rehearsal mode
<?php
if(isset($rehearseScript) && !empty($rehearseScript)) { 
    echo '    const selectedDecks = JSON.parse(\'' . $selectedDecks . '\');' . PHP_EOL;
    echo $rehearseScript . PHP_EOL;
};
?>

    function initIndexedDB() {
        // initialising database
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('FlashcardsDB', 1);

            request.onupgradeneeded = function(event) {
                // creating cards store when no database yet exists
                db = event.target.result;
                if (!db.objectStoreNames.contains('cards')) {
                    db.createObjectStore('cards', { keyPath: 'id' });
                }
            };

            request.onsuccess = function(event) {
                // assigning the db instance to the global variable
                db = event.target.result;
                resolve();
            };

            request.onerror = function(event) {
                console.error('Error opening database:', event);
                reject(new Error('Error opening database'));
            };
        });
    }

    function loadCardsIntoDB(deckData) {
        // loading cards from currently selected decks into database
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readwrite');
            const store = transaction.objectStore('cards');
            
            deckData.forEach(card => {
                // checking if card with same ID already exists, adding new card otherwise
                const getRequest = store.get(card.id);                                
                getRequest.onsuccess = function() {
                    if (!getRequest.result) {                        
                        // rounding initial time to minute precision
                        const now = new Date();
                        now.setSeconds(0, 0);
                        
                        store.add({
                            ...card,
                            repetition: 0,
                            interval: 0,
                            easeFactor: 2.5,
                            activeDirection: 'front',
                            nextReviewDate: now.getTime(),
                        });
                    };
                };
            });

            transaction.oncomplete = function() {
                resolve();
            };

            transaction.onerror = function(event) {
                console.error('Error loading cards into database', event);
                reject(new Error('Error loading cards into database'));
            };
        });
    }
    
    function getDueCardsFromDB(selectedDecks) {
        // getting cards from database that are in the selected decks and due for review
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readonly');
            const store = transaction.objectStore('cards');
            const now = Date.now();
            const dueCards = [];

            const request = store.openCursor();
            request.onsuccess = event => {
                const cursor = event.target.result;
                if (cursor) {
                    // pushing all due cards
                    let card = cursor.value;
                    if (selectedDecks.includes(card.deckFilename) && card.nextReviewDate <= now) {
                        dueCards.push(card);
                    }
                    cursor.continue();
                } else {
                    // resolving promise when there are no further entries
                    resolve(dueCards);
                }
            };

            request.onerror = function(event) {
                console.error('Failed to fetch due cards:', event);
                reject(new Error('Failed to fetch due cards'));
            };
        });
    }

    function selectNextCard(selectedDecks) {
        // selecting next card for review
        return new Promise((resolve, reject) => {
            getDueCardsFromDB(selectedDecks).then(dueCards => {
                if (dueCards.length === 0) {
                    // no cards due
                    resolve(null);
                    return;
                }

                // finding card with earliest nextReviewDate, selecting random in case there's multiple
                const earliestReviewDate = Math.min(...dueCards.map(card => card.nextReviewDate));
                const earliestDueCards = dueCards.filter(card => card.nextReviewDate === earliestReviewDate);
                const cardToShow = earliestDueCards[Math.floor(Math.random() * earliestDueCards.length)];

                resolve(cardToShow);
            }).catch(error => {
                reject(error);
            });
        });
    }

    function displayCard(card) {
        // showing card to user
        if (card) {
            currentCard = card;            
            console.log('Showing card:', card);
            
            // selecting which side to present first
            if (card.activeDirection === 'both') {
                currentDirection = Math.random() < 0.5 ? 'front' : 'back';
            } else {
                currentDirection = card.activeDirection;
            }

            // updating html to display card            
            const probeDiv = document.getElementById('probe');
            const answerDiv = document.getElementById('answer');
            
            // answerDiv.style.display = 'none';
            
            if(currentDirection === 'front') {
                probeDiv.textContent = card.front;
                answerDiv.textContent = card.back;
            } else {
                probeDiv.textContent = card.back;
                answerDiv.textContent = card.front;
            }
        }
    }
    </script>
    <noscript>Unfortunately, this page requires JavaScript, which your browser does not support.</noscript>
</head>
    
<body>
    <div id="main">
<?php 
    if(isset($deckHTML) && !empty($deckHTML)) { echo $deckHTML; }
    elseif(isset($rehearseHTML) && !empty($rehearseHTML)) { echo $rehearseHTML; };
?>
    </div>
</body>
</html>