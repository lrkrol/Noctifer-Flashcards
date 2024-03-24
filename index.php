<?php

$deckDirectory = './decks';   // relative path to search for deck json files
$easeFactor = 2.5;            // ease factor, i.e., factor with which to increase interval after "good" responses
$hardEaseFactor = 1.2;        // ease factor for "hard" responses
$interval = 1;                // default interval in days
$directionSwitch = 4;         // number of correct repetitions after which card direction can be changed


if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['decks'])) {
    // POST data indicates decks have been selected; switching to rehearse mode
    $selectedDecks = json_encode($_POST['decks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    $rehearseScript = rehearse($deckDirectory, array_map(function($deck) {return preg_replace('/[^a-zA-Z0-9_\-\.]+/', '', $deck);}, $_POST['decks']));
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
    $deckHTML = '<h1>Which decks would you like to rehearse?</h1>' . PHP_EOL . '<form action="' . basename(__FILE__) . '" method="POST">' . PHP_EOL . '<fieldset>' . PHP_EOL;
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
                    // sanitizing and fixing audio path
                    $audioPath = $card['audio'];
                    $audioPath = str_replace("../", "", $audioPath);
                    $audioPath = str_replace("..\\", "", $audioPath);
                    $audioPath = str_replace(DIRECTORY_SEPARATOR, '/', $deckDirectory . $audioPath);
                    if (file_exists($audioPath)) {
                        $card['audio'] = $audioPath;
                    }
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
    
    document.addEventListener('keydown', function(event) {
        switch(event.code) {
            case 'Space':
                event.preventDefault(); // Prevent the default action (scrolling) when pressing space
                showAnswer();
                break;
            case 'Digit1':
            case 'Numpad1':
                event.preventDefault();
                updateCardProgress('again').then(() => { 
                    selectNextCard(selectedDecks).then(displayCard); 
                });
                break;
            case 'Digit2':
            case 'Numpad2':
                event.preventDefault();
                updateCardProgress('hard').then(() => { 
                    selectNextCard(selectedDecks).then(displayCard); 
                });
                break;
            case 'Digit3':
            case 'Numpad3':
                event.preventDefault();
                updateCardProgress('good').then(() => { 
                    selectNextCard(selectedDecks).then(displayCard); 
                });
                break;
        }
    });
    
EOT;

    return $rehearseScript;
}

?>


<!DOCTYPE html>
<head>
    <title>Flashcard training</title>
    <script>
    let db;
    let currentCard = null;
    let currentSide = null;


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
                            interval: <?php echo $interval; ?>,
                            easeFactor: <?php echo $easeFactor; ?>,
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
    
    
    function getDueCards(selectedDecks) {
        // getting cards from database that are in the selected decks and due for review
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['cards'], 'readonly');
            const store = transaction.objectStore('cards');
            const dueDate = Date.now() + (15 * 60 * 1000);
            const dueCards = [];

            const request = store.openCursor();
            request.onsuccess = function(event) {
                const cursor = event.target.result;
                if (cursor) {
                    // pushing all due cards
                    let card = cursor.value;
                    if (selectedDecks.includes(card.deckFilename) && card.nextReviewDate <= dueDate) {
                        dueCards.push(card);
                    }
                    cursor.continue();
                } else {
                    // resolving promise when there are no further entries
                    console.log(dueCards);
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
            getDueCards(selectedDecks).then(dueCards => {
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
    
    function bb2html(text) {
        let convertedText = text;
        
        convertedText = convertedText.replace(/\[b\](.*?)\[\/b\]/g, '<strong>$1</strong>');        
        convertedText = convertedText.replace(/\[i\](.*?)\[\/i\]/g, '<em>$1</em>');        
        convertedText = convertedText.replace(/\[u\](.*?)\[\/u\]/g, '<u>$1</u>');
        
        return convertedText;
    }


    function displayCard(card) {
        // showing card to user
        if (card) {
            currentCard = card;            
            
            // selecting which side to present first
            if (card.activeDirection === 'both') {
                currentSide = Math.random() < 0.5 ? 'front' : 'back';
            } else {
                currentSide = card.activeDirection;
            }

            // updating html to display card            
            const probeDiv = document.getElementById('probe');
            const answerDiv = document.getElementById('answer');
            
            answerDiv.style.visibility = 'hidden';
            document.getElementById('show').style.display = 'block';
            document.getElementById('again').style.display = 'none';
            document.getElementById('hard').style.display = 'none';
            document.getElementById('good').style.display = 'none';
            
            if(currentSide === 'front') {
                probeDiv.innerHTML = bb2html(card.front);
                answerDiv.innerHTML = bb2html(card.back);
                if (card.audio) {
                    playAudio(card.audio);
                }
            } else {
                probeDiv.innerHTML = bb2html(card.back);
                answerDiv.innerHTML = bb2html(card.front);
            }
        } else {
            // no card to display
            document.getElementById('probe').textContent = 'All done!';
            document.getElementById('answer').textContent = 'There are no cards to display.';
            document.getElementById('responses').style.visibility = 'hidden';
        }
    }

    
    function playAudio(audioPath) {
        const audio = new Audio(audioPath);
        audio.play().catch(error => console.error("Error playing audio:", error));
    }
    
    
    function showAnswer() {
        document.getElementById('answer').style.visibility = 'visible';
        document.getElementById('show').style.display = 'none';
        document.getElementById('again').style.display = 'inline-block';
        document.getElementById('hard').style.display = 'inline-block';
        document.getElementById('good').style.display = 'inline-block';
        
        console.log(currentSide);
        
        if(currentSide === 'back' && currentCard.audio) {
            playAudio(currentCard.audio);
        }
    }
    
    
    function updateCardProgress(response) {
        if (!currentCard) return;

        const newReviewTime = new Date();
        switch (response) {
            case 'again':
                newReviewTime.setMinutes(newReviewTime.getMinutes() + 1);
                currentCard.interval = <?php echo $interval; ?>;
                currentCard.repetition = 0;
                currentCard.activeDirection = currentSide;
                break;
            case 'hard':
                if (currentCard.repetition === 0) {
                    newReviewTime.setMinutes(newReviewTime.getMinutes() + 10);
                    currentCard.interval = <?php echo $hardEaseFactor; ?>;
                } else {
                    newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval);
                    currentCard.interval = Math.round(currentCard.interval * <?php echo $hardEaseFactor; ?> * 10) / 10;
                }
                currentCard.repetition += 1;
                break;
            case 'good':
                if (currentCard.repetition === 0) {
                    newReviewTime.setMinutes(newReviewTime.getMinutes() + 10);
                    currentCard.interval = <?php echo $hardEaseFactor; ?>;
                } else {
                    newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval);
                    currentCard.interval = Math.round(currentCard.interval * currentCard.easeFactor * 10) / 10;
                }
                currentCard.repetition += 1;
                break;
        }
        
        currentCard.nextReviewDate = newReviewTime.getTime();
        
        // changing activeDirection after a number of successful repetitions, if allowed
        if (currentCard.allowDirectionChange && currentCard.repetition % <?php echo $directionSwitch; ?> === 0 && currentCard.repetition > 0) {
            switch (currentCard.activeDirection) {
                case 'front':
                    currentCard.activeDirection = 'back';
                    break;
                case 'back':
                    currentCard.activeDirection = 'both';
                    break;
                case 'both':
                    // no change required if already both
                    break;
            }
        }

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
            font-size: large;
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
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            font-size: xx-large;
        }
        
        #responses > div {
            flex: 1;
            padding: .5em 0;
            cursor: pointer;
        }
        
        #responses > div:hover {
            color: var(--fg-highlight);
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

        @media (min-width: 810px) {
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