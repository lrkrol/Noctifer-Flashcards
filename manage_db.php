<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Flashcard database</title>
    <script>
        let db;

        document.addEventListener('DOMContentLoaded', function() {
            initIndexedDB().then(loadCards);
        });
        
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
        
        
        function loadCards() {
            const transaction = db.transaction(['cards'], 'readonly');
            const store = transaction.objectStore('cards');
            const getRequest = store.getAll();

            getRequest.onsuccess = function() {
                const cards = getRequest.result;
                if(cards.length > 0) {
                    updateTableHeaders(cards[0]);
                }

                const tableBody = document.getElementById('cardsTable').getElementsByTagName('tbody')[0];
                
                // clearing table
                tableBody.innerHTML = '';

                cards.forEach(card => {
                    // inserting row for each card
                    const row = tableBody.insertRow();
                    
                    // inserting cell for each value
                    Object.entries(card).forEach(([key, value]) => {
                        const cell = row.insertCell();
                        if (Number.isInteger(value) && value > 1000000) {
                            // this is probably a timestamp; converting
                            cell.textContent = new Date(value).toISOString().split('.')[0];
                        } else {
                            cell.textContent = value;
                        };
                    });

                    // inserting cell for delete button
                    const deleteCell = row.insertCell();
                    const deleteButton = document.createElement('button');
                    deleteButton.textContent = 'Delete';
                    deleteButton.onclick = function() { deleteCard(card.id); };
                    deleteCell.appendChild(deleteButton);
                });
            };
        };

        const updateTableHeaders = (sampleCard) => {
            // putting keys as headers
            const tableHead = document.getElementById('cardsTable').getElementsByTagName('thead')[0];
            tableHead.innerHTML = '';
            const headerRow = tableHead.insertRow();

            Object.keys(sampleCard).forEach(key => {
                const headerCell = document.createElement('th');
                headerCell.textContent = key;
                headerRow.appendChild(headerCell);
            });

            // adding header for buttons
            if (!sampleCard.hasOwnProperty('action')) {
                const actionHeaderCell = document.createElement('th');
                headerRow.appendChild(actionHeaderCell);
            }
        };

        const deleteCard = (cardId) => {
            const transaction = db.transaction(['cards'], 'readwrite');
            const store = transaction.objectStore('cards');
            store.delete(cardId);

            transaction.oncomplete = function() {
                loadCards();
            };
        };
        
        function deleteDatabase() {
            return new Promise((resolve, reject) => {
                
                const userConfirmed = confirm("This will permanently delete the all of the locally stored flashcard data.");

                if (!userConfirmed) {
                    console.log("Database deletion canceled by user.");
                    reject(new Error("Database deletion canceled by user."));
                    return;
                }
                
                // closing connection
                if (db) {
                    console.log("Closing database connections...");
                    db.close();
                } else {
                    // no database to delete
                    console.log("No database to delete.");
                    reject(new Error("No database to delete."));
                    return;
                };

                // proceeding with the database deletion with some delay to ensure proper closure
                setTimeout(() => {
                    const request = indexedDB.deleteDatabase('FlashcardsDB');
                    request.onsuccess = function () {
                        console.log("Database deleted successfully.");
                        db = null;

                        const tableHead = document.getElementById('cardsTable').getElementsByTagName('thead')[0];
                        tableHead.innerHTML = '';
                        const tableBody = document.getElementById('cardsTable').getElementsByTagName('tbody')[0];
                        tableBody.innerHTML = '';
                        
                        resolve(db);
                    };
                    request.onerror = function () {
                        reject(new Error("Error deleting the database."));
                    };
                    request.onblocked = function () {
                        reject(new Error("Database deletion blocked. Make sure all connections are closed."));
                    };
                }, 100);
            });
        }
        
        function exportDatabase() {
            const transaction = db.transaction(['cards'], 'readonly');
            const store = transaction.objectStore('cards');
            const allRecordsRequest = store.getAll();

            allRecordsRequest.onsuccess = function() {
                const data = allRecordsRequest.result;
                const dataStr = JSON.stringify(data);
                const blob = new Blob([dataStr], {type: "application/json"});

                // creating link and triggering download
                const a = document.createElement("a");
                a.href = URL.createObjectURL(blob);
                a.download = "flashcards_backup.json";
                document.body.appendChild(a); 
                a.click(); // simulating click to trigger the download
                document.body.removeChild(a);
            };
        }
        
        function importDatabase() {
            const input = document.getElementById('fileInput');
            if (input.files.length === 0) {
                alert('Please select a file to import.');
                return;
            }

            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = function(event) {
                const data = JSON.parse(event.target.result);

                // deleting existing database, then initialising new one
                deleteDatabase().then(() => {
                    console.log("Importing data...");
                    initIndexedDB().then(() => {
                        const transaction = db.transaction(['cards'], 'readwrite');
                        const store = transaction.objectStore('cards');

                        data.forEach(card => {
                            store.add(card);
                        });

                        transaction.oncomplete = function() {
                            console.log("Data imported successfully");
                            loadCards();
                        };

                        transaction.onerror = function(event) {
                            console.error('Error importing data:', event);
                        };
                    }).catch(error => {
                        console.error('Error initializing the database:', error);
                    });
                }).catch((error) => {
                    console.error("An error occurred during the database deletion:", error);
                });
            };

            reader.onerror = function() {
                alert('Error reading the file');
            };

            reader.readAsText(file);
        }
    </script>
    <style>
        body {
            --bg-color: #fbf5f3;
            --fg-color: #386641;
            --bg-highlight: #fff;
            --fg-highlight: #6a994e;
            
            font-family: sans-serif;
            color: var(--fg-color);
            background-color: var(--bg-color);
        }
        
        h1 {
            font-size: x-large;
        }
        
        fieldset {
            border: 1px solid var(--fg-color);
            background-color: var(--bg-highlight);
            border-radius: 5px;
            margin: 0;
        }
        
        table {
            border-collapse: collapse;
            margin-bottom: 10px
        }
        
        th, td {
            padding: 5px 10px;
        }
        
        tr:hover {
            color: var(--fg-highlight);
        }
        
        button, input {
            display: inline-block;
            color: var(--bg-color);
            background-color: var(--fg-color);
            border: none;
            border-radius: 5px;
            padding: 2px 10px;
            cursor: pointer;
        }
        
        button {
            padding: 5px 10px;
        }
        
        button:hover {
            background-color: var(--fg-highlight);
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
    <h1>Flashcard database management</h1>
    <fieldset>
        <legend>Flashcards</legend>
        <table id="cardsTable">
            <thead>
            </thead>
            <tbody>
            </tbody>
        </table>
        <button onclick="exportDatabase()">Export Database</button>
        <input type="file" id="fileInput" />
        <button onclick="importDatabase()">Import Database</button>
        <button onclick="deleteDatabase()">Delete Database</button>
    </fieldset>
</body>
</html>