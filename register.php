<?php
require 'config.php';
require 'includes/send_email.php';

$name = $email = $password = $confirm_password = $phone = $gender = "";
$errors = [];
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name             = htmlspecialchars(trim($_POST['name']));
    $email            = htmlspecialchars(trim($_POST['email']));
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone            = htmlspecialchars(trim($_POST['phone']));
    $gender           = isset($_POST['gender']) ? $_POST['gender'] : '';

    // Validation
    if (empty($name))     { $errors[] = "Name is required."; }
    if (empty($email))    { $errors[] = "Email is required."; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Invalid email format."; }
    if (empty($password)) { $errors[] = "Password is required."; }
    if (strlen($password) < 6) { $errors[] = "Password must be at least 6 characters."; }
    if ($password !== $confirm_password) { $errors[] = "Passwords do not match."; }
    if (empty($phone))    { $errors[] = "Phone is required."; }
    if (empty($gender))   { $errors[] = "Gender is required."; }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id, is_verified FROM users WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($existing_id, $existing_verified);
            $stmt->fetch();
            $stmt->close();
            if ($existing_verified) {
                $errors[] = "Email is already registered. Login করো।";
            } else {
                // Unverified old entry — delete it so fresh insert works
                $conn->query("DELETE FROM users WHERE id = $existing_id");
            }
        } else {
            $stmt->close();
        }
    } else {
        $errors[] = "Database error.";
    }

    // Handle profile photo upload
    $photo_path = 'img/user.png'; // default
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $target_dir = "img/user_photos/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $new_filename = 'user_reg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target_file  = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
            } else {
                $errors[] = "Failed to upload photo. Default photo will be used.";
            }
        } else {
            $errors[] = "Invalid photo format. Allowed: jpg, jpeg, png, gif.";
        }
    }

    // If no errors, insert the user
    if (empty($errors)) {
        $hashed_password    = password_hash($password, PASSWORD_DEFAULT);
        $verification_token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare(
            "INSERT INTO users (name, email, password, phone, gender, photo, is_verified, verification_token, token_created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())"
        );
        if ($stmt) {
            $stmt->bind_param("sssssss", $name, $email, $hashed_password, $phone, $gender, $photo_path, $verification_token);
            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                // Send verification email via SMTP
                $emailSent = sendVerificationEmail($email, $name, $verification_token);
                if ($emailSent) {
                    $success = true;
                } else {
                    // Email failed — delete user so they can retry
                    $conn->query("DELETE FROM users WHERE id = $new_user_id");
                    $errors[] = "Verification email পাঠানো যায়নি। পরে আবার চেষ্টা করো।";
                }
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
            $stmt->close();
        } else {
            $errors[] = "Database error.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up - NoteNest</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/register.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ── Photo Picker ── */
        .photo-picker-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 10px 0 6px;
        }
        .photo-ring {
            position: relative;
            width: 90px;
            height: 90px;
            cursor: pointer;
        }
        .photo-ring img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #197f8f;
            transition: opacity 0.2s;
        }
        .photo-ring:hover img { opacity: 0.75; }
        .photo-ring .edit-icon {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: #197f8f;
            color: #fff;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        .photo-hint {
            font-size: 11px;
            color: #aaa;
            margin-top: 5px;
        }
        #photoInput { display: none; }

        /* ── Success Box ── */
        .success-box {
            text-align: center;
            padding: 40px 30px;
        }
        .success-box .icon { font-size: 64px; margin-bottom: 16px; }
        .success-box h2   { color: #0b4954; margin-bottom: 10px; }
        .success-box p    { color: #555; font-size: 15px; line-height: 1.7; }
        .success-box a {
            display: inline-block;
            margin-top: 20px;
            background: linear-gradient(135deg, #0b4954, #197f8f);
            color: #fff;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container" id="container">

    <?php if ($success): ?>
    <!-- ✅ Registration Successful -->
    <div class="form-container sign-in-container">
        <div class="success-box">
            <div class="icon">📬</div>
            <h2>Check Your Email!</h2>
            <p>
                আমরা <strong><?php echo htmlspecialchars($email); ?></strong>-এ একটি verification email পাঠিয়েছি।<br><br>
                Email-এর ভেতরের <strong>"Verify Email"</strong> বোতামে ক্লিক করো, তারপর Login করতে পারবে।
            </p>
            <p style="font-size:13px;color:#aaa;margin-top:8px;">Spam folder-ও চেক করো।</p>
            <a href="login.php"><i class="fas fa-arrow-left"></i> Login Page-এ যাও</a>
        </div>
    </div>

    <?php else: ?>
    <!-- 📝 Sign Up Form -->
    <div class="form-container sign-in-container">
        <form action="register.php" method="POST" enctype="multipart/form-data">
            <h1>Create NoteNest Account</h1>

            <!-- Profile Photo Picker -->
            <div class="photo-picker-wrap">
                <div class="photo-ring" onclick="document.getElementById('photoInput').click()">
                    <img id="photoPreview" src="img/user.png" alt="Profile Photo">
                    <div class="edit-icon"><i class="fas fa-camera"></i></div>
                </div>
                <span class="photo-hint">Profile Photo (optional)</span>
                <input type="file" id="photoInput" name="photo" accept="image/jpg,image/jpeg,image/png,image/gif"
                       onchange="previewPhoto(this)">
            </div>

            <input type="text"     name="name"             placeholder="Full Name"        value="<?php echo htmlspecialchars($name); ?>"  required>
            <input type="email"    name="email"            placeholder="Email"            value="<?php echo htmlspecialchars($email); ?>" required>
            <input type="password" name="password"         placeholder="Password"         required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <input type="text"     name="phone"            placeholder="Phone"            value="<?php echo htmlspecialchars($phone); ?>" required>
            <select name="gender" required>
                <option value="">Select Gender</option>
                <option value="Male"   <?php if($gender=='Male')   echo 'selected'; ?>>Male</option>
                <option value="Female" <?php if($gender=='Female') echo 'selected'; ?>>Female</option>
                <option value="Other"  <?php if($gender=='Other')  echo 'selected'; ?>>Other</option>
            </select>

            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $error) echo $error . "<br>"; ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-color">Sign Up</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="overlay-container">
        <div class="overlay">
            <div class="overlay-panel overlay-right">
                <h1>Already have an account?</h1>
                <p>Sign in to start securely managing your notes and files!</p>
                <button class="ghost"><a href="login.php">Sign In</a></button>
            </div>
        </div>
    </div>
</div>

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photoPreview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
</body>
</html>