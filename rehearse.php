<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['decks'])) {
    $selectedDecks = $_POST['decks'];
    $decksDirectory = "decks/";
    $decksData = [];

    foreach ($selectedDecks as $deckFilename) {
        $filePath = $decksDirectory . $deckFilename;
        if (file_exists($filePath)) {
            $deckContent = json_decode(file_get_contents($filePath), true);
            $allowDirectionChange = $deckContent['header']['allowDirectionChange'] ?? false; // Default to false if not specified

            foreach ($deckContent['cards'] as &$card) {
                $card['id'] = $deckFilename . '#' . $card['id'];
                $card['deckFilename'] = $deckFilename; // Optionally include the deck filename as a separate field
                $card['activeDirection'] = 'front'; // Default starting direction
                $card['allowDirectionChange'] = $allowDirectionChange;
            }
            unset($card); // Break the reference with the last element

            $decksData = array_merge($decksData, $deckContent['cards']); // Flatten all cards into a single array
        }
    }

    $decksDataJson = json_encode($decksData); // Encode the combined decks data as JSON for client-side use
    
    print_r($decksDataJson);
} else {
    echo 'No decks selected. Please go back and select at least one deck.';
    exit;
}
?>


<script>
let db; // Global variable to hold the IndexedDB instance

document.addEventListener('DOMContentLoaded', function() {
    initIndexedDB().then(() => {
        const decksData = <?php echo json_encode($decksData); ?>;
        loadCardsIntoDB(decksData).then(() => {
            displayCards();
        });
    });
});

function initIndexedDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('FlashcardsDB', 1);

        request.onupgradeneeded = function(event) {
            db = event.target.result;
            if (!db.objectStoreNames.contains('cards')) {
                db.createObjectStore('cards', { keyPath: 'id' });
            }
        };

        request.onsuccess = function(event) {
            db = event.target.result; // Assign the db instance to the global variable
            resolve();
        };

        request.onerror = function(event) {
            console.error('Error opening IndexedDB:', event);
            reject(new Error('Error opening IndexedDB'));
        };
    });
}

function loadCardsIntoDB(decksData) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(['cards'], 'readwrite');
        const store = transaction.objectStore('cards');
        
        decksData.forEach(card => {
            // Check if the card already exists to avoid overwriting SM2 progress data
            const getRequest = store.get(card.id);
            getRequest.onsuccess = function() {
                if (!getRequest.result) { // If the card doesn't exist, add it
                    store.add({
                        ...card,
                        repetition: 0,
                        interval: 0,
                        easeFactor: 2.5,
                        // activeDirection and allowDirectionChange are handled here if needed
                    });
                }
                // Existing cards are not updated to preserve progress
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
        const displayArea = document.getElementById('cardsDisplayArea');
        displayArea.innerHTML = ''; // Clear previous contents

        cards.forEach(card => {
            const cardElement = document.createElement('div');
            cardElement.style.marginBottom = "20px"; // Add some spacing between cards

            // Dynamically create a list of properties for each card
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



<body>
    <h2>IndexedDB Contents</h2>
    <ul id="cardsDisplayArea"></ul>
</body>