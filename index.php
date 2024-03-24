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
            <div id="show" onClick="showAnswer();">Show answer</div>
            <div id="again" onClick="updateCardProgress('again').then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Again</div>
            <div id="hard" onClick="updateCardProgress('hard').then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Hard</div>
            <div id="good" onClick="updateCardProgress('good').then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Good</div>
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
    $deckHTML = '<h1>Select decks for rehearsal</h1>' . PHP_EOL . '<form action="' . basename(__FILE__) . '" method="POST">' . PHP_EOL . '<fieldset>' . PHP_EOL;
    foreach ($decks as $deck) {
        $deckHTML = $deckHTML . '    <div id="decks">'. PHP_EOL;
        $deckHTML = $deckHTML . '        <label><input type="checkbox" name="decks[]" value="' . htmlspecialchars($deck['filename']) . '" /><span>'. htmlspecialchars($deck['name']) . '<span></label>';
        $deckHTML = $deckHTML . '    </div>'. PHP_EOL;
    }
    $deckHTML = $deckHTML . '</fieldset>' . PHP_EOL . '<input type="submit" value="Start rehearsal" />' . PHP_EOL . '</form>'. PHP_EOL;
    
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
                selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})
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
                getRequest.onsuccess = function(event) {
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
            request.onsuccess = function(event) {
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
            
            // selecting which side to present first
            if (card.activeDirection === 'both') {
                currentDirection = Math.random() < 0.5 ? 'front' : 'back';
            } else {
                currentDirection = card.activeDirection;
            }

            // updating html to display card            
            const probeDiv = document.getElementById('probe');
            const answerDiv = document.getElementById('answer');
            
            answerDiv.style.visibility = 'hidden';
            document.getElementById('show').style.display = 'block';
            document.getElementById('again').style.display = 'none';
            document.getElementById('hard').style.display = 'none';
            document.getElementById('good').style.display = 'none';
            
            if(currentDirection === 'front') {
                probeDiv.textContent = card.front;
                answerDiv.textContent = card.back;
            } else {
                probeDiv.textContent = card.back;
                answerDiv.textContent = card.front;
            }
        } else {
            // no card to display
            document.getElementById('probe').textContent = 'All done!';
            document.getElementById('answer').textContent = 'There are no cards to display.';
            document.getElementById('responses').style.visibility = 'hidden';
        }
    }
    
    function showAnswer() {
        document.getElementById('answer').style.visibility = 'visible';
        document.getElementById('show').style.display = 'none';
        document.getElementById('again').style.display = 'inline-block';
        document.getElementById('hard').style.display = 'inline-block';
        document.getElementById('good').style.display = 'inline-block';
    }
    
    function updateCardProgress(response) {
        if (!currentCard) return; // Ensure there's a card to update

        const newReviewTime = new Date();
        switch (response) {
            case 'again':
                newReviewTime.setMinutes(newReviewTime.getMinutes() + 1);
                currentCard.interval = 1; // setting interval to 1 day
                currentCard.repetition = 0;
                break;
            case 'hard':
                newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval); // Update nextReviewDate based on new interval
                currentCard.interval = currentCard.interval * 1.2;
                currentCard.repetition += 1;
                break;
            case 'good':
                if (currentCard.repetition === 0) {
                    newReviewTime.setMinutes(newReviewTime.getMinutes() + 10);
                    currentCard.interval = 1;
                } else {
                    newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval);
                    currentCard.interval = Math.round(currentCard.interval * currentCard.easeFactor);
                }
                currentCard.repetition += 1;
                break;
        }
        
        currentCard.nextReviewDate = newReviewTime.getTime(); // Convert nextReviewDate to timestamp

        // saving updated card back into the database
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readwrite');
            const store = transaction.objectStore('cards');
            const updateRequest = store.put(currentCard);

            updateRequest.onsuccess = function(event) {
                resolve(currentCard);
            };

            updateRequest.onerror = function(event) {
                console.error('Error updating card:', event);
                reject(new Error('Could not update card'));
            };
        });
    }
    
    </script>
    <noscript>Unfortunately, this page requires JavaScript, which your browser does not support.</noscript>
    <style>
        body {
            --bg-color: #fbf5f3;
            --fg-color: #386641;
            --bg-highlight: #fff;
            --fg-highlight: #6a994e;
            
            font-size: xxx-large;
            font-family: sans-serif;
            color: var(--fg-color);
            background-color: var(--bg-color);
        }
        
        #main {
            width: 100%;
            box-sizing: border-box;
            padding: 20px;
        }
        
        h1 {
            font-size: x-large;
        }
        
        fieldset {
            font-size: large;
            border: 1px solid var(--fg-color);
            background-color: var(--bg-highlight);
            border-radius: 5px;
            margin: 0;
        }
        
        input[type=checkbox] {
            display: none;
        }
        
        input[type=checkbox] + span {
            cursor: pointer;
            display: block;
            margin: 10px 0;
        }
        
        input[type=checkbox]:checked + span {
            color: var(--fg-highlight);
        }
        
        input[type=checkbox] + span:hover {
            color: var(--fg-highlight);
        }
        
        input[type=checkbox] + span:before {
            content: "\2714";
            margin-right: 5px;
            color: var(--bg-highlight);
        }
        
        input[type=checkbox]:checked + span:before {
            content: "\2714";
            color: var(--fg-highlight);
        }
        
        input[type=submit] {
            display: block;
            width: 100%;
            color: var(--bg-color);
            background-color: var(--fg-color);
            border: none;
            border-radius: 5px;
            margin: 20px auto 0 auto;
            padding: 10px;
            cursor: pointer;
        }
        
        input[type=submit]:hover {
            background-color: var(--fg-highlight);
        }
        
        #probe {
            text-align: center;
            padding: 2em 0; 
        }
        
        #answer {
            text-align: center;
            padding: 2em 0; 
            border-top: 1px solid grey;
        }
        
        #responses {
            display: flex;
            width: inherit;
            position: fixed;
            bottom: 0;
            text-align: center;
            font-size: xx-large;
        }
        
        #responses > div {
            flex: 1;
            padding: .5em 0;
            cursor: pointer;
        }
        
        #again {
            text-decoration: underline;
            text-decoration-color: darkred;
        }
        
        #hard {
            text-decoration: underline;
            text-decoration-color: darkslategrey;
        }
        
        #good {
            text-decoration: underline;
            text-decoration-color: green;
        }

        @media (min-width: 768px) {
            #main{
                width: 800px;
                margin: 0 auto;
            }
        }
        
        @media (prefers-color-scheme: dark) {
            body {
                --bg-color: #000000;
                --fg-color: #e5e5e5;
                --bg-highlight: #181d27;
                --fg-highlight: #a7c957;
            }
        }
    </style>

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