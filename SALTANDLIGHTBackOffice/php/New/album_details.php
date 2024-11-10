<?php
// Include the database connection
include_once("../../connections/db.php");
include_once("check_session.php");

// Initialize variables
$id = $label = $isActive = '';
$isEditing = false;
$album = ['ID' => '', 'Label' => '', 'IsActive' => 1, 'CreatedDate' => '', 'UpdatedDate' => '', 'CreatedBy' => '', 'UpdatedBy' => ''];  // Ensure IsActive is always present

// Check if we're editing an album
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "SELECT * FROM Album WHERE ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $album = $result->fetch_assoc();
    $isEditing = true;
}

// Handle form submission for album save or update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['saveAlbum'])) {
        $label = $_POST['Label'];
        $isActive = isset($_POST['IsActive']) ? 1 : 0;  // Checkbox for IsActive
        $updatedDate = date('Y-m-d');  // Current date for update
        $updatedBy = 1;  // Assuming the logged-in user is ID 1
        $createdBy = 1;  // Assuming the logged-in user is ID 1
        $createdDate = date('Y-m-d');  // Current date for creation

        if ($isEditing) {
            $stmt = $conn->prepare("UPDATE Album SET Label = ?, IsActive = ?, UpdatedDate = ?, UpdatedBy = ? WHERE ID = ?");
            $stmt->bind_param("siiii", $label, $isActive, $updatedDate, $updatedBy, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO album (Label, IsActive, CreatedDate, CreatedBy, UpdatedDate, UpdatedBy) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siiiii", $label, $isActive, $createdDate, $createdBy, $updatedDate, $updatedBy);
        }

        if ($stmt->execute()) {
            $albumID = $isEditing ? $id : $conn->insert_id;
            header('Location: album_details.php?id=' . $albumID);
            exit();
        } else {
            echo "Error: " . $conn->error;
        }
    }

    // Handle file upload
    if (isset($_POST['saveFiles']) && isset($_FILES['files'])) {
        $albumID = $_POST['albumID'];
        $uploadedBy = 1;  // Assuming logged-in user ID
        $uploadedDate = date('Y-m-d');
        $isActive = 1;  // Set files as active by default
    
        $files = $_FILES['files'];
    
        // Fetch the album label from the database using the album ID
        $sqlAlbum = "SELECT Label FROM Album WHERE ID = ?";
        $stmtAlbum = $conn->prepare($sqlAlbum);
        $stmtAlbum->bind_param("i", $albumID);
        $stmtAlbum->execute();
        $stmtAlbum->bind_result($albumLabel);
        $stmtAlbum->fetch();
        $stmtAlbum->close();
    
        if ($albumLabel) {
            // Use the album label as part of the file path
            $albumFolder = 'files/' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $albumLabel);  // Sanitize the folder name
            if (!is_dir($albumFolder)) {
                mkdir($albumFolder, 0777, true);  // Create album folder if it doesn't exist
            }
    
            for ($i = 0; $i < count($files['name']); $i++) {
                $fileName = $files['name'][$i];
                $fileTmpName = $files['tmp_name'][$i];
    
                // Create the full file path dynamically within the album folder
                $filePath = $albumFolder . '/' . $fileName;
    
                // Move the uploaded file to the server in the album folder
                if (move_uploaded_file($fileTmpName, $filePath)) {
                    // Insert file info into the database
                    $sqlFile = "INSERT INTO Files (AlbumID, Label, FileName, Path, UploadedBy, UploadedDate, IsActive, isIntegrated) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtFile = $conn->prepare($sqlFile);
                    $fileLabel = $fileName;  // You can modify this to use a custom label for each file if needed
                    $isIntegrated = 0;  // Assuming the file is not integrated by default (you can adjust this as needed)
                    $stmtFile->bind_param("isssisii", $albumID, $fileLabel, $fileName, $filePath, $uploadedBy, $uploadedDate, $isActive, $isIntegrated);
                    $stmtFile->execute();
                }
            }
        } else {
            echo "Album not found!";
        }
    }
    

  // Handle file deletion
if (isset($_POST['deleteSelectedFiles']) && isset($_POST['selectedFiles'])) {
    $selectedFiles = $_POST['selectedFiles'];

    foreach ($selectedFiles as $fileID) {
        // Fetch file from database to get the file path and album ID
        $sqlDelete = "SELECT * FROM Files WHERE ID = ?";
        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->bind_param("i", $fileID);
        $stmtDelete->execute();
        $resultDelete = $stmtDelete->get_result();
        $fileToDelete = $resultDelete->fetch_assoc();

        if ($fileToDelete) {
            // Get the album ID and file path from the database record
            $albumID = $fileToDelete['AlbumID'];
            $filePathToDelete = $fileToDelete['Path'];

            // Fetch the album label to reconstruct the folder path
            $sqlAlbum = "SELECT Label FROM Album WHERE ID = ?";
            $stmtAlbum = $conn->prepare($sqlAlbum);
            $stmtAlbum->bind_param("i", $albumID);
            $stmtAlbum->execute();
            $stmtAlbum->bind_result($albumLabel);
            $stmtAlbum->fetch();
            $stmtAlbum->close();

            if ($albumLabel) {
                // Reconstruct the album folder path
                $albumFolder = 'files/' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $albumLabel);  // Ensure a valid folder name
                $fullFilePathToDelete = $albumFolder . '/' . basename($filePathToDelete); // Add the file name to the folder path

                // Delete the file from the server if it exists
                if (file_exists($fullFilePathToDelete)) {
                    unlink($fullFilePathToDelete);  // Remove the file
                }

                // Delete the file entry from the database
                $sqlDeleteFile = "DELETE FROM Files WHERE ID = ?";
                $stmtDeleteFile = $conn->prepare($sqlDeleteFile);
                $stmtDeleteFile->bind_param("i", $fileID);
                $stmtDeleteFile->execute();
            }
        }
    }
}


    // Handle file move to another album
    if (isset($_POST['moveFiles']) && isset($_POST['selectedFiles']) && isset($_POST['albumID'])) {
        $selectedFiles = $_POST['selectedFiles'];
        $newAlbumID = $_POST['albumID'];

        foreach ($selectedFiles as $fileID) {
            // Update album ID for selected files
            $sqlMove = "UPDATE Files SET AlbumID = ? WHERE ID = ?";
            $stmtMove = $conn->prepare($sqlMove);
            $stmtMove->bind_param("ii", $newAlbumID, $fileID);
            $stmtMove->execute();
        }

        // Redirect to avoid re-submitting the form
        header('Location: album_details.php?id=' . $id);
        exit();
    }
}

// Fetch all albums for the "Move to Album" dropdown
$albumsQuery = "SELECT ID, Label FROM Album";
$albumsResult = $conn->query($albumsQuery);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Album Details</title>

    <!-- Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">

    <!-- Link to Sidebar CSS -->
    <link href="../../css/sidebar.css" rel="stylesheet">

    <!-- Link to Custom CSS -->
    <link href="../../css/custom.css" rel="stylesheet">
</head>

<body>
    <!-- Side Navbar -->
    <div class="sidebar">
        <span class="menu-toggle" id="menuToggle">&#9776;</span> <!-- Hamburger Icon -->
        <h3 class="text-center text-white mb-4">Menu</h3>
        <a href="dashboard.php">Dashboard</a>
        <a href="user_list.php">Users</a>
        <a href="role_list.php">Roles</a>
        <a href="position_list.php">Positions</a>
        <a href="album_list.php">Albums</a>
        <a href="file_list.php">Files</a>
        <a href="user_details.php">User Details</a>

        <!-- Logout Button -->
        <div class="text-center mt-4">
            <button class="logout-btn" onclick="window.location.href='logout.php'">Logout</button>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row justify-content-center">
            <!-- Album Details Card -->
            <div class="col-12 col-md-8 col-lg-10">
                <div class="card">
                    <div class="card-body">
                        <h1 class="text-center"><?= $isEditing ? 'Edit Album' : 'Create Album' ?></h1>

                        <form method="post" class="mt-4">
                            <!-- Album Label -->
                            <div class="form-group">
                                <label for="Label">Album Name</label>
                                <input type="text" class="form-control" id="Label" name="Label"
                                    value="<?= $album['Label'] ?>" required>
                            </div>

                            <!-- Is Active Checkbox -->
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="IsActive" name="IsActive"
                                    <?= isset($album['IsActive']) && $album['IsActive'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="IsActive">Is Active</label>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" name="saveAlbum"
                                class="btn btn-primary btn-sm mt-3"><?= $isEditing ? 'Update Album' : 'Create Album' ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- File Upload Form -->
            <div class="col-12 col-md-8 col-lg-10 mt-4">
                <div class="card">
                    <div class="card-body">
                        <h4>Upload Files</h4>
                        <form method="post" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="files">Select Files</label>
                                <input type="file" class="form-control-file" name="files[]" multiple required>
                                <input type="hidden" name="albumID" value="<?= $album['ID'] ?>">
                            </div>
                            <button type="submit" name="saveFiles" class="btn btn-primary btn-sm">Upload Files</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- File Gallery -->
            <div class="col-12 col-md-8 col-lg-10 mt-4">
                <div class="card">
                    <div class="card-body">
                        <h4>Uploaded Files</h4>

                        <!-- Move to Album and Delete Buttons -->


                        <form method="post">
                            <div class="d-flex mb-3">
                                <button type="submit" id="deleteButton" class="btn btn-danger btn-sm mr-2"
                                    name="deleteSelectedFiles" style="display: none;">Delete Selected Files</button>
                                <button type="button" class="btn btn-warning btn-sm " id="moveButton"
                                    data-toggle="modal" data-target="#moveModal" style="display: none;">Move to
                                    Album</button>

                            </div>
                            <div class="row mt-3">
                                <?php
                                // Fetch files for this album
                                $sqlFiles = "SELECT * FROM files WHERE AlbumID = ?";
                                $stmtFiles = $conn->prepare($sqlFiles);
                                $stmtFiles->bind_param("i", $album['ID']);
                                $stmtFiles->execute();
                                $resultFiles = $stmtFiles->get_result();

                                while ($file = $resultFiles->fetch_assoc()) {
                                    echo '<div class="col-md-3 mb-3">';
                                    echo '<div class="card position-relative">';
                                    echo '<img src="' . $file['Path'] . '" class="card-img-top" alt="File Thumbnail">';
                                    echo '<input type="checkbox" name="selectedFiles[]" value="' . $file['ID'] . '" class="position-absolute top-0 right-0 m-2" onchange="toggleButtons()">';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                ?>
                            </div>

                            <!-- Move to Album Modal -->
                            <div class="modal fade" id="moveModal" tabindex="-1" role="dialog"
                                aria-labelledby="moveModalLabel" aria-hidden="true">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="moveModalLabel">Move Files to Another Album</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="post">
                                                <div class="form-group">
                                                    <label for="albumID">Select Album</label>
                                                    <select class="form-control" name="albumID" id="albumID">
                                                        <option value="">Select an Album</option>
                                                        <?php
                                                        while ($albumOption = $albumsResult->fetch_assoc()) {
                                                            echo '<option value="' . $albumOption['ID'] . '">' . $albumOption['Label'] . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <button type="submit" name="moveFiles"
                                                    class="btn btn-primary btn-sm">Move Files</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>



        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

    <script>
        // Toggle "Move to Album" and "Delete" button visibility
        function toggleButtons() {
            const checkboxes = document.querySelectorAll('input[name="selectedFiles[]"]');
            const moveButton = document.getElementById('moveButton');
            const deleteButton = document.getElementById('deleteButton');
            let isSelected = false;

            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) {
                    isSelected = true;
                }
            });

            moveButton.style.display = isSelected ? 'inline-block' : 'none';
            deleteButton.style.display = isSelected ? 'inline-block' : 'none';
        }
    </script>
</body>

</html>