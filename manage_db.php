<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Database</title>
    <style>
        table, th, td {
            border: 1px solid black;
            border-collapse: collapse;
            padding: 5px;
            text-align: left;
        }
        table {
            margin-bottom: 5px;
        }
    </style>
    <script>
        let db;

        document.addEventListener('DOMContentLoaded', function() {
            initIndexedDB().then(loadCards);
        });
        
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
                tableBody.innerHTML = ''; // Clear existing rows

                cards.forEach(card => {
                    const row = tableBody.insertRow();
                    
                    // Display the 'id' field alongside other properties
                    Object.entries(card).forEach(([key, value]) => {
                        const cell = row.insertCell();
                        cell.textContent = value;
                    });

                    // Append a delete button to each row for card deletion functionality
                    const deleteCell = row.insertCell();
                    const deleteButton = document.createElement('button');
                    deleteButton.textContent = 'Delete';
                    deleteButton.onclick = function() { deleteCard(card.id); };
                    deleteCell.appendChild(deleteButton);
                });
            };
        };

        const updateTableHeaders = (sampleCard) => {
            const tableHead = document.getElementById('cardsTable').getElementsByTagName('thead')[0];
            tableHead.innerHTML = ''; // Clear existing headers
            const headerRow = tableHead.insertRow();

            Object.keys(sampleCard).forEach(key => {
                const headerCell = document.createElement('th');
                headerCell.textContent = key.charAt(0).toUpperCase() + key.slice(1); // Capitalize the key for the header
                headerRow.appendChild(headerCell);
            });

            // Optionally, add a header cell for the action column, if not already represented in sampleCard
            if (!sampleCard.hasOwnProperty('action')) {
                const actionHeaderCell = document.createElement('th');
                actionHeaderCell.textContent = 'Action';
                headerRow.appendChild(actionHeaderCell);
            }
        };

        const deleteCard = (cardId) => {
            const transaction = db.transaction(['cards'], 'readwrite');
            const store = transaction.objectStore('cards');
            store.delete(cardId);

            transaction.oncomplete = function() {
                loadCards(); // Reload cards to update the table
            };
        };
        
        function deleteDatabase() {
            return new Promise((resolve, reject) => {
                
                const userConfirmed = confirm("This will permanently delete the all of the locally stored flashcard data.");

                if (!userConfirmed) {
                    console.log("Database deletion canceled by user.");
                    reject(new Error("Database deletion canceled by user."));
                    return; // Exit the function if the user cancels
                }
                
                if (db) { // Check if the db connection exists
                    console.log("Closing database connections...");
                    db.close(); // Close the active connection
                }

                // Proceed with the database deletion with some delay to ensure proper closure
                setTimeout(() => {
                    const request = indexedDB.deleteDatabase('FlashcardsDB');
                    request.onsuccess = function () {
                        console.log("Database deleted successfully.");
                        db = null; // Reset the db variable

                        const tableHead = document.getElementById('cardsTable').getElementsByTagName('thead')[0];
                        tableHead.innerHTML = ''; // Clear existing headers
                        const tableBody = document.getElementById('cardsTable').getElementsByTagName('tbody')[0];
                        tableBody.innerHTML = ''; // Clear existing rows
                        
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

                // Create a link and trigger the download
                const a = document.createElement("a");
                a.href = URL.createObjectURL(blob);
                a.download = "flashcards_backup.json";
                document.body.appendChild(a); // Append the link to the body
                a.click(); // Simulate click to trigger the download
                document.body.removeChild(a); // Remove the link after triggering the download
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
                // Parse the file content to JSON
                const data = JSON.parse(event.target.result);

                // Delete the existing database and then recreate and import data
                deleteDatabase().then(() => {
                    console.log("Importing data...");
                    initIndexedDB().then(() => {
                        // Now that the database is initialized, start the transaction
                        const transaction = db.transaction(['cards'], 'readwrite');
                        const store = transaction.objectStore('cards');

                        // Iterate through the data array and add each item to the database
                        data.forEach(card => {
                            store.add(card); // Add each card to the database
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

            reader.readAsText(file); // Read the file content
        }
    </script>
</head>
<body>
    <h2>Database Management</h2>
    <fieldset>
        <legend>Flashcards</legend>
        <table id="cardsTable">
            <thead>
            </thead>
            <tbody>
            </tbody>
        </table>
        <button onclick="deleteDatabase()">Delete Database</button>
        <button onclick="exportDatabase()">Export Database</button>
        <input type="file" id="fileInput" />
        <button onclick="importDatabase()">Import Database</button>
    </fieldset>
</body>
</html>