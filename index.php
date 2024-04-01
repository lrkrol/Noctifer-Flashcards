<?php

$deckDirectory = './decks';   // relative path to search for deck json files
$easeFactor = 2.5;            // default ease factor
$interval = 1;                // default interval in days
$directionSwitch = 3;         // number of correct repetitions after which card direction can be changed
$pickFromEarliest = 25;       // next card will be selected randomly from the earliest X due cards


if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['decks'])) {
    // POST data indicates decks have been selected; switching to rehearse mode
    $selectedDecks = json_encode($_POST['decks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    $rehearseScript = rehearse($deckDirectory, array_map(function($deck) {return preg_replace('/[^a-zA-Z0-9_\-\.]+/', '', $deck);}, $_POST['decks']));
    $rehearseHTML = <<<EOL
        <div id="probe"></div>
        <div id="answer"></div>
        <div id="responses">
            <div id="show" onClick="showAnswer();">Show answer</div>
            <div id="again" onClick="updateCardProgress(0).then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Again</div>
            <div id="hard" onClick="updateCardProgress(3).then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Hard</div>
            <div id="good" onClick="updateCardProgress(5).then(() => {selectNextCard(selectedDecks).then(nextCard => {displayCard(nextCard);})});">Good</div>
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
        $deckHTML = $deckHTML . '    <div>'. PHP_EOL;
        $deckHTML = $deckHTML . '        <label><input type="checkbox" name="decks[]" value="' . htmlspecialchars($deck['filename']) . '"><span>'. htmlspecialchars($deck['name']) . '</span></label>';
        $deckHTML = $deckHTML . '    </div>'. PHP_EOL;
    }
    $deckHTML = $deckHTML . '</fieldset>' . PHP_EOL . '<input type="submit" value="Start rehearsal">' . PHP_EOL . '</form>'. PHP_EOL;

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
                if (!empty($card['audioFront'])) {
                    // sanitizing and fixing audio path
                    $audioPath = $card['audioFront'];
                    $audioPath = str_replace("../", "", $audioPath);
                    $audioPath = str_replace("..\\", "", $audioPath);
                    $audioPath = str_replace(DIRECTORY_SEPARATOR, '/', $deckDirectory . $audioPath);
                    if (file_exists($audioPath)) {
                        $card['audioFront'] = $audioPath;
                    }
                }
                if (!empty($card['audioBack'])) {
                    // sanitizing and fixing audio path
                    $audioPath = $card['audioBack'];
                    $audioPath = str_replace("../", "", $audioPath);
                    $audioPath = str_replace("..\\", "", $audioPath);
                    $audioPath = str_replace(DIRECTORY_SEPARATOR, '/', $deckDirectory . $audioPath);
                    if (file_exists($audioPath)) {
                        $card['audioBack'] = $audioPath;
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
                updateCardProgress(0).then(() => {
                    selectNextCard(selectedDecks).then(displayCard);
                });
                break;
            case 'Digit2':
            case 'Numpad2':
                event.preventDefault();
                updateCardProgress(3).then(() => {
                    selectNextCard(selectedDecks).then(displayCard);
                });
                break;
            case 'Digit3':
            case 'Numpad3':
                event.preventDefault();
                updateCardProgress(5).then(() => {
                    selectNextCard(selectedDecks).then(displayCard);
                });
                break;
        }
    });

EOT;

    return $rehearseScript;
}

?>
<!doctype html>
<html lang="en">
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

                // selecting at random from 10 earliest-due cards
                dueCards.sort((a, b) => a.nextReviewDate - b.nextReviewDate);
                const earliestDueCards = dueCards.slice(0, Math.min(<?php echo $pickFromEarliest; ?>, dueCards.length));
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
                if (card.audioFront) {
                    playAudio(card.audioFront);
                }
            } else {
                probeDiv.innerHTML = bb2html(card.back);
                answerDiv.innerHTML = bb2html(card.front);
                if (card.audioBack) {
                    playAudio(card.audioBack);
                }
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

        if(currentSide === 'back' && currentCard.audioFront) {
            playAudio(currentCard.audioFront);
        } else if(currentSide === 'front' && currentCard.audioBack) {
            playAudio(currentCard.audioBack);
        }
    }


    function updateCardProgress(quality) {
        if (!currentCard) return;

        const newReviewTime = new Date();

        if (quality < 3) {
            currentCard.interval = <?php echo $interval; ?>;
            currentCard.repetition = 0;
            currentCard.activeDirection = currentSide;
            newReviewTime.setMinutes(newReviewTime.getMinutes() + 10);
        } else {
            if (currentCard.repetition === 0) {
                currentCard.interval = <?php echo $interval; ?>;
                newReviewTime.setMinutes(newReviewTime.getMinutes() + 10);
            } else if (currentCard.repetition === 1) {
                currentCard.interval = <?php echo $interval; ?>;
                newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval);
            } else {
                currentCard.interval = Math.round(currentCard.interval * currentCard.easeFactor * 10) / 10;
                newReviewTime.setDate(newReviewTime.getDate() + currentCard.interval);
            }
        }

        // changing activeDirection after a number of successful repetitions, if allowed
        if (currentCard.allowDirectionChange && currentCard.repetition % <?php echo $directionSwitch; ?> === 0 && currentCard.repetition > 0) {
            switch (currentCard.activeDirection) {
                case 'front':
                    currentCard.activeDirection = 'back';
                    newReviewTime.setDate(newReviewTime.getDate() + 1);
                    break;
                case 'back':
                    currentCard.activeDirection = 'both';
                    break;
                case 'both':
                    // no change required if already both
                    break;
            }
        }
        
        easeFactor = Math.round((currentCard.easeFactor + (0.1-(5-quality)*(0.08+(5-quality)*0.02))) * 100) / 100;
        if (easeFactor < 1.3) { easeFactor = 1.3; }

        currentCard.repetition += 1;
        currentCard.nextReviewDate = newReviewTime.getTime();
        currentCard.easeFactor = easeFactor;

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
    <style>
        body {
            --bg-color: #f6fafc;
            --fg-color: #0a2e3c;
            --bg-highlight: #fff;
            --fg-highlight: #086086;

            font-family: sans-serif;
            color: var(--fg-color);
            background-color: var(--bg-color);
        }

        #main {
            box-sizing: border-box;
            width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        h1 {
            font-size: xx-large;
        }

        fieldset {
            font-size: x-large;
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

        input[type=checkbox] + span:before {
            content: "\2714";
            margin-right: 5px;
            color: var(--bg-highlight);
        }

        input[type=checkbox]:checked + span,
        input[type=checkbox]:checked + span:before {
            color: var(--fg-highlight);
        }

        input[type=checkbox] + span:active {
            opacity: 0.5;
        }

        input[type=submit] {
            width: 100%;
            font-size: x-large;
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

        #probe, #answer {
            font-size: 56px;
            text-align: center;
            padding: 2em 0;
        }

        #answer {
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
            display: none;
        }

        #responses > div:hover {
            color: var(--fg-highlight);
        }

        #responses > div:active {
            opacity: 0.5;
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

        @media (orientation: portrait) {
            #main{
                width: 100%;
            }

            h1, fieldset, input[type=submit], #responses {
                font-size: 48px;
            }

            input[type=checkbox] + span {
                margin: 20px 0;
            }

            #probe, #answer {
                font-size: 72px;
            }
        }

        @media (orientation: landscape) {
            input[type=checkbox] + span:hover,
            input[type=checkbox] + span:hover:before {
                color: var(--fg-highlight);
            }
        }

        @media (prefers-color-scheme: dark) {
            body {
                --bg-color: #000000;
                --fg-color: #deeef4;
                --bg-highlight: #01161e;
                --fg-highlight: #ffffff;
            }
        }
    </style>
</head>

<body>
    <noscript>Unfortunately, this page requires JavaScript, which your browser does not support.</noscript>
    <div id="main">
<?php
    if(isset($deckHTML) && !empty($deckHTML)) { echo $deckHTML; }
    elseif(isset($rehearseHTML) && !empty($rehearseHTML)) { echo $rehearseHTML; };
?>
    </div>
</body>
</html>