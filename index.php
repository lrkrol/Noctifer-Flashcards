<?php


$deckDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'decks';


if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['decks'])) {
    // POST data indicates decks have been selected; switching to rehearsing mode
    $rehearseScript = rehearse($deckDirectory, $_POST['decks']);
    $rehearseHTML = '<div id="probe"></div>' . PHP_EOL . '<div id="answer"></div>' . PHP_EOL;
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
                $card['activeDirection'] = 'front'; // default starting direction
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
            loadCardsIntoDB(deckData).then(() => {displayCards();});
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
    
    // reproducing rehearsal script data here in rehearsal mode
<?php if(isset($rehearseScript) && !empty($rehearseScript)) { echo $rehearseScript; }; ?>

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
                getRequest.onsuccess = function() {
                    if (!getRequest.result) {
                        store.add({
                            ...card,
                            repetition: 0,
                            interval: 0,
                            easeFactor: 2.5,
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

    function displayCards() {
        const transaction = db.transaction(['cards'], 'readonly');
        const store = transaction.objectStore('cards');
        const request = store.getAll();

        request.onsuccess = function() {
            const cards = request.result;
            const displayArea = document.getElementById('main');
            displayArea.innerHTML = '';

            cards.forEach(card => {
                const cardElement = document.createElement('div');
                cardElement.style.marginBottom = "20px";

                for (const [key, value] of Object.entries(card)) {
                    const propertyElement = document.createElement('p');
                    propertyElement.style.marginBottom = "0px";
                    propertyElement.style.marginTop = "0px";
                    propertyElement.textContent = `${key}: ${value}`;
                    cardElement.appendChild(propertyElement);
                }

                displayArea.appendChild(cardElement);
            });
        };

        request.onerror = function() {
            console.error('Error fetching cards from DB');
        };
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