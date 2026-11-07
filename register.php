
<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    $dest = ($_SESSION['role'] ?? '') === 'Applicant'
        ? '/applicant/index.php'
        : '/dashboard.php';

    header('Location: ' . BASE_URL . $dest);
    exit;
}

$errors = [];
$full = $username = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($full === '' || mb_strlen($full) > 120) {
        $errors[] = 'Please enter your full name.';
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $errors[] = 'Username must contain 3–50 valid characters.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $pdo = null;

        try {
            $pdo = db();

            $check = $pdo->prepare(
                'SELECT user_id FROM users WHERE username = ? LIMIT 1'
            );
            $check->execute([$username]);

            if ($check->fetch()) {
                $errors[] = 'Username is already taken.';
            } else {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (username, password_hash, full_name, role, status)
                    VALUES (?, ?, ?, 'Applicant', 'Active')
                ");

                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $full
                ]);

                $uid = (int)$pdo->lastInsertId();

                $profile = $pdo->prepare("
                    INSERT INTO applicant_profiles (user_id, email)
                    VALUES (?, ?)
                ");

                $profile->execute([$uid, $email]);

                $pdo->commit();

                flash('success', 'Applicant account created successfully. You may now sign in.');

                header('Location: ' . BASE_URL . '/index.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Applicant registration: ' . $e->getMessage());

            $errors[] = 'Registration failed. Please check your database setup.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Applicant Registration</title>

<link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/style.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
* {
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    margin: 0;
    padding: 24px;
    background: linear-gradient(135deg,#142b43,#315b80);
    display: grid;
    place-items: center;
    font-family: Arial,sans-serif;
}

.auth-card {
    width: min(100%,520px);
    background: #fff;
    padding: 34px;
    border-radius: 20px;
    box-shadow: 0 24px 70px #07182d55;
    animation: enter .45s ease;
}

@keyframes enter {
    from {opacity:0;transform:translateY(16px)}
    to {opacity:1;transform:translateY(0)}
}

.brandmark {
    font-size: 13px;
    font-weight: 800;
    color: #3157d5;
    letter-spacing: 1px;
}

h1 {
    color: #172b40;
    margin: 12px 0;
}

.intro {
    color: #718096;
    line-height: 1.5;
}

label {
    display: block;
    margin: 15px 0 7px;
    font-size: 13px;
    font-weight: 700;
    color: #344054;
}

.auth-card input {
    width: 100%;
    padding: 13px;
    border: 1px solid #d8e0eb;
    border-radius: 9px;
    outline: none;
    font-size: 14px;
}

.auth-card input:focus {
    border-color: #3157d5;
    box-shadow: 0 0 0 3px #3157d51f;
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 52px;
}

.toggle-password {
    position: absolute;
    right: 5px;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    border: 0;
    background: transparent;
    cursor: pointer;
    font-size: 19px;
}

.submit-btn {
    width: 100%;
    margin-top: 22px;
    padding: 14px;
    border: 0;
    border-radius: 9px;
    background: #3157d5;
    color: white;
    font-weight: 700;
    cursor: pointer;
}

.submit-btn:hover {
    background: #2444b4;
}

.divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 0 14px;
    color: #98a2b3;
    font-size: 11px;
    font-weight: 700;
}

.divider:before,
.divider:after {
    content: "";
    height: 1px;
    background: #e4e7ec;
    flex: 1;
}

.social-icons {
    display: flex;
    justify-content: center;
    gap: 14px;
}

.social-icon {
    width: 48px;
    height: 48px;
    border: 1px solid #d8e0eb;
    border-radius: 12px;
    background: white;
    display: grid;
    place-items: center;
    transition: .2s;
}

.social-icon:hover {
    background: #f4f7fb;
    border-color: #3157d5;
    transform: translateY(-3px);
}

.social-icon svg {
    width: 23px;
    height: 23px;
}

.social-note {
    text-align: center;
    color: #98a2b3;
    font-size: 11px;
    margin-top: 10px;
}

.auth-footer {
    text-align: center;
    margin-top: 22px;
    font-size: 13px;
    color: #718096;
}

.auth-footer a {
    color: #3157d5;
    font-weight: 700;
    text-decoration: none;
}

.error-text {
    color: #dc2626;
    font-size: 13px;
}

@media(max-width:480px) {
    body {padding:14px}
    .auth-card {padding:24px 20px}
}
</style>
</head>

<body>

<main class="auth-card">

    <div class="brandmark">DORMITORY MANAGEMENT</div>

    <h1>Create Applicant Account</h1>

    <p class="intro">
        Register to view available rooms and submit your dormitory reservation request.
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="error-text"><?= h($error) ?></p>
    <?php endforeach; ?>

    <form method="POST">

        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <label>Full Name</label>
        <input type="text" name="full_name" maxlength="120"
               placeholder="Enter your full name" required
               value="<?= h($full) ?>">

        <label>Username</label>
        <input type="text" name="username" maxlength="50"
               placeholder="Choose a username" required
               value="<?= h($username) ?>">

        <label>Email Address</label>
        <input type="email" name="email"
               placeholder="Enter your email address"
               required value="<?= h($email) ?>">

        <label>Password (at least 8 characters)</label>
        <div class="password-wrapper">
            <input type="password" id="password" name="password"
                   minlength="8" placeholder="Enter your password"
                   required autocomplete="new-password">

            <button class="toggle-password" type="button"
                    onclick="togglePassword('password',this)">Show</button>
        </div>

        <label>Confirm Password</label>
        <div class="password-wrapper">
            <input type="password" id="confirm_password"
                   name="confirm_password" minlength="8"
                   placeholder="Confirm your password"
                   required autocomplete="new-password">

            <button class="toggle-password" type="button"
                    onclick="togglePassword('confirm_password',this)">Show</button>
        </div>

        <button type="submit" class="submit-btn">
            Create Applicant Account
        </button>

    </form>

    <div class="divider">OR CONTINUE WITH</div>

    <div class="social-icons">

        <a class="social-icon"
           href="<?= h(BASE_URL) ?>/oauth/start.php?provider=google"
           aria-label="Continue with Google">

            <svg viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.75 7.18l7.73 6C44.43 37.95 46.98 31.8 46.98 24.55z"/>
                <path fill="#FBBC05" d="M10.54 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.98-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.14 1.44-4.89 2.3-8.18 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
        </a>

        <a class="social-icon"
           href="<?= h(BASE_URL) ?>/oauth/start.php?provider=facebook"
           aria-label="Continue with Facebook">

            <svg viewBox="0 0 24 24">
                <path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.167 10.125 24v-8.437H7.078v-3.49h3.047V9.413c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.98h-1.514c-1.49 0-1.955.93-1.955 1.884v2.257h3.328l-.532 3.49h-2.796V24C19.612 23.167 24 18.1 24 12.073z"/>
            </svg>
        </a>

    </div>

    <p class="social-note">Social sign-in is for Applicants only.</p>

    <div class="auth-footer">
        Already registered?
        <a href="<?= h(BASE_URL) ?>/index.php">Sign in</a>
    </div>

</main>

<script>
function togglePassword(id, button) {
    const input = document.getElementById(id);
    if (!input) return;

    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'Hide' : 'Show';
}
</script>

<?php if ($errors): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Registration Error',
    text: <?= json_encode(implode(' ', $errors)) ?>,
    confirmButtonColor: '#3157d5'
});
</script>
<?php endif; ?>

</body>
</html>
