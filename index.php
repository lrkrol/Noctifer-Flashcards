<?php


$deckDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'decks';


if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['decks'])) {
    // POST data indicates decks have been selected; switching to rehearsing mode
    $rehearseScript = rehearse($deckDirectory, $_POST['decks']);
    $rehearseHTML = '<div id="probe"></div>' . PHP_EOL . '<div id="answer"></div>' . PHP_EOL;
    $selectedDecks = json_encode($_POST['decks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
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
            }
            unset($card);

            // flattening all cards into a single array
            $deckData = array_merge($deckData, $deckContent['cards']);
        }
    }

    $deckData = json_encode($deckData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    
    print_r($deckData);
    
    // producing script to load data into database, needed for rehearsal
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
        
    // reproducing rehearsal code here in rehearsal mode
<?php
if(isset($rehearseScript) && !empty($rehearseScript)) { 
    echo $rehearseScript;
    echo 'const selectedDecks = JSON.parse(\'' . $selectedDecks . '\');';
};
?>

    function initIndexedDB() {
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
                console.error('Error opening IndexedDB:', event);
                reject(new Error('Error opening IndexedDB'));
            };
        });
    }

    function loadCardsIntoDB(deckData) {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readwrite');
            const store = transaction.objectStore('cards');
            
            deckData.forEach(card => {
                // checking if card with same ID already exists, adding new card otherwise
                const getRequest = store.get(card.id);                
                
                // rounding initial time to minute precision
                const now = new Date();
                now.setSeconds(0, 0);
                
                getRequest.onsuccess = function() {
                    if (!getRequest.result) {
                        store.add({
                            ...card,
                            repetition: 0,
                            interval: 0,
                            easeFactor: 2.5,
                            activeDirection: 'front',
                            nextReviewDate: now.getTime(),
                        });
                    }
                };
            });

            transaction.oncomplete = function() {
                resolve();
            };

            transaction.onerror = function() {
                reject(new Error('Error loading cards into DB'));
            };
        });
    }
    
    function getDueCardsFromDB(selectedDecks) {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readonly');
            const store = transaction.objectStore('cards');
            const now = Date.now();
            const dueCards = [];

            const request = store.openCursor();
            request.onsuccess = event => {
                const cursor = event.target.result;
                if (cursor) {
                    let card = cursor.value;
                    // Check if the card's deck is in selectedDecks and if the card is due for review
                    if (selectedDecks.includes(card.deckFilename) && card.nextReviewDate <= now) {
                        dueCards.push(card);
                    }
                    cursor.continue();
                } else {
                    // No more entries, resolve the promise with the filtered due cards
                    resolve(dueCards);
                }
            };

            request.onerror = event => {
                reject('Failed to fetch due cards:', event.target.error);
            };
        });
    }

    function selectNextCard(selectedDecks) {
        return new Promise((resolve, reject) => {
            getDueCardsFromDB(selectedDecks).then(dueCards => {
                if (dueCards.length === 0) {
                    resolve(null); // No cards are due for review
                    return;
                }

                // Find the earliest nextReviewDate among the due cards
                const earliestReviewDate = Math.min(...dueCards.map(card => card.nextReviewDate));

                // Filter for cards that match this earliest nextReviewDate
                const earliestDueCards = dueCards.filter(card => card.nextReviewDate === earliestReviewDate);

                // Select a card at random if there are multiple, or just select the card if there's only one
                const cardToShow = earliestDueCards[Math.floor(Math.random() * earliestDueCards.length)];

                resolve(cardToShow);
            }).catch(error => {
                reject(error);
            });
        });
    }

    function displayCard(card) {
        if (card) {
            // Implement logic to display the card
            // For example, showing the 'front' or 'back' based on 'activeDirection'
            console.log('Showing card:', card);
            const probeDiv = document.getElementById('probe');
            const answerDiv = document.getElementById('answer');

            // Resetting the answer div to hide the previous answer
            // answerDiv.style.display = 'none';

            // Displaying the probe side of the card
            if(card.activeDirection === 'front') {
                probeDiv.textContent = card.front;
                answerDiv.textContent = card.back; // Prepare the answer to be shown later
            } else {
                probeDiv.textContent = card.back;
                answerDiv.textContent = card.front; // Prepare the answer to be shown later
            }
        }
    }


    </script>
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