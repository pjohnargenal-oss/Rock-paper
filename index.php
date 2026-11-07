
<?php

require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (is_logged_in()) {
    header(
        'Location: ' . BASE_URL .
        (($_SESSION['role'] ?? '') === 'Applicant'
            ? '/applicant/index.php'
            : '/dashboard.php')
    );
    exit;
}

$error = '';
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();
    $lockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
    if ($lockUntil > time()) {
        $error = 'Too many sign-in attempts. Please wait ' . (int)ceil(($lockUntil - time()) / 60) . ' minute(s).';
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $error = 'Please enter your username and password.';

    } else {

        try {

            $pdo = db();

            $stmt = $pdo->prepare("
                SELECT
                    user_id,
                    username,
                    password_hash,
                    full_name,
                    role,
                    status
                FROM users
                WHERE username = :username
                LIMIT 1
            ");

            $stmt->execute(['username' => $username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {

                $passwordValid = password_verify(
                    $password,
                    (string) $user['password_hash']
                );

                if (
                    $passwordValid &&
                    ($user['status'] ?? 'Active') === 'Active'
                ) {

                    session_regenerate_id(true);
                    unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);

                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'] ?? 'Admin';

                    $destination = (
                        ($user['role'] ?? '') === 'Applicant'
                    )
                        ? '/applicant/index.php'
                        : '/dashboard.php';

                    header('Location: ' . BASE_URL . $destination);
                    exit;

                } else {
                    $error = 'Invalid username/password or inactive account.';
                }

            } else {
                $error = 'Invalid username or password.';
            }

        } catch (Throwable $e) {

            error_log($e->getMessage());

            $error = 'Unable to sign in right now. Please check the database configuration.';
        }
    }
    if ($error !== '') {
        $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_lock_until'] = time() + 900;
            $_SESSION['login_attempts'] = 0;
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Sign In | Dormitory Management System</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: Arial, sans-serif;
}

body {
    min-height: 100vh;
    background: #edf1f6;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 25px;
}

.login-layout {
    width: 100%;
    max-width: 1150px;
    min-height: 650px;
    display: grid;
    grid-template-columns: 430px 1fr;
    background: white;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 20px 65px rgba(17, 39, 62, .15);
}

/* LEFT LOGIN PANEL */

.login-panel {
    padding: 48px 42px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 48px;
}

.logo {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 13px;
    background: #efb75f;
    color: #17314c;
    font-size: 23px;
    font-weight: bold;
}

.brand h2 {
    color: #172b40;
    font-size: 20px;
}

.brand small {
    display: block;
    color: #8495aa;
    font-size: 9px;
    letter-spacing: 1.5px;
    margin-top: 5px;
}

h1 {
    color: #172b40;
    font-size: 28px;
    margin-bottom: 10px;
}

.subtitle {
    color: #8493a7;
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 28px;
}

.error {
    padding: 13px;
    background: #fff0f0;
    border: 1px solid #ffd6d6;
    color: #c43d3d;
    border-radius: 9px;
    font-size: 12px;
    margin-bottom: 18px;
    overflow-wrap: anywhere;
}

label {
    display: block;
    color: #31465d;
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 8px;
}

input {
    width: 100%;
    padding: 14px;
    border: 1px solid #dce4ed;
    border-radius: 9px;
    outline: none;
    font-size: 13px;
    margin-bottom: 20px;
}

input:focus {
    border-color: #244968;
    box-shadow: 0 0 0 3px rgba(36,73,104,.08);
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 65px;
}

.toggle-password {
    position: absolute;
    right: 10px;
    top: 0;
    padding: 13px 8px;
    background: transparent;
    color: #244968;
    border: none;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
}

.login-button {
    width: 100%;
    padding: 15px;
    background: #1d3856;
    color: white;
    border: none;
    border-radius: 9px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    transition: .2s;
}

.login-button:hover {
    background: #294e73;
    transform: translateY(-2px);
}

.register-link {
    text-align: center;
    margin-top: 24px;
    color: #718096;
    font-size: 13px;
}

.register-link a {
    color: #3157d5;
    font-weight: bold;
    text-decoration: none;
}

/* RIGHT IMAGE SLIDESHOW */

.slideshow {
    position: relative;
    min-height: 650px;
    overflow: hidden;
    background: #172b40;
}

.slide {
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 1s ease;
}

.slide.active {
    opacity: 1;
}

.slide img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.slide-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to top,
        rgba(9, 24, 40, .85),
        rgba(9, 24, 40, .05) 75%
    );
}

.slide-caption {
    position: absolute;
    left: 45px;
    right: 45px;
    bottom: 75px;
    color: white;
    z-index: 2;
}

.slide-caption h2 {
    font-size: 31px;
    margin-bottom: 12px;
}

.slide-caption p {
    font-size: 14px;
    line-height: 1.7;
    color: #e5edf5;
    max-width: 440px;
}

.slide-indicators {
    position: absolute;
    bottom: 35px;
    left: 45px;
    display: flex;
    gap: 9px;
    z-index: 5;
}

.indicator {
    width: 9px;
    height: 9px;
    border: none;
    border-radius: 20px;
    background: rgba(255,255,255,.5);
    cursor: pointer;
    transition: .3s;
}

.indicator.active {
    width: 27px;
    background: #efb75f;
}

.slide-controls {
    position: absolute;
    right: 35px;
    bottom: 25px;
    display: flex;
    gap: 9px;
    z-index: 5;
}

.slide-controls button {
    width: 37px;
    height: 37px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.5);
    background: rgba(255,255,255,.15);
    color: white;
    cursor: pointer;
    font-size: 17px;
}

.slide-controls button:hover {
    background: #efb75f;
    color: #172b40;
}

/* MOBILE */

@media (max-width: 800px) {

    body {
        padding: 15px;
    }

    .login-layout {
        max-width: 480px;
        grid-template-columns: 1fr;
    }

    .login-panel {
        padding: 35px 28px;
    }

    .brand {
        margin-bottom: 35px;
    }

    .slideshow {
        min-height: 300px;
        order: -1;
    }

    .slide-caption {
        left: 25px;
        right: 25px;
        bottom: 65px;
    }

    .slide-caption h2 {
        font-size: 23px;
    }

    .slide-caption p {
        font-size: 12px;
    }

    .slide-indicators {
        left: 25px;
        bottom: 25px;
    }

    .slide-controls {
        right: 20px;
        bottom: 15px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .slide {
        transition: none;
    }
}

</style>
</head>

<body>

<div class="login-layout">

    <!-- LOGIN FORM -->

    <section class="login-panel">

        <div class="brand">
            <div class="logo">D</div>

            <div>
                <h2>Dormitory</h2>
                <small>MANAGEMENT SYSTEM</small>
            </div>
        </div>

        <h1>Welcome back</h1>

        <p class="subtitle">
            Sign in to manage your dormitory workspace.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <label for="username">USERNAME</label>

            <input
                type="text"
                id="username"
                name="username"
                placeholder="Enter username"
                value="<?= h($username) ?>"
                autocomplete="username"
                required
            >

            <label for="password">PASSWORD</label>

            <div class="password-wrapper">

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter password"
                    autocomplete="current-password"
                    required
                >

                <button
                    type="button"
                    id="togglePassword"
                    class="toggle-password"
                    aria-label="Show password"
                    aria-pressed="false"
                >Show</button>

            </div>

            <button type="submit" class="login-button">
                Sign in to dashboard →
            </button>

        </form>

        <p class="register-link">
            New applicant?
            <a href="<?= h(BASE_URL) ?>/register.php">
                Create an account
            </a>
        </p>

    </section>

    <!-- AUTOMATIC IMAGE SLIDESHOW -->

    <section class="slideshow" aria-label="Dormitory photo slideshow">

        <div class="slide active">
            <img src="<?= h(BASE_URL) ?>/reservations/uploads/dorm-1.jpg"
                 alt="Dormitory interior"
                 fetchpriority="high">
            <div class="slide-overlay"></div>
            <div class="slide-caption">
                <h2>Your Comfortable Home Away From Home</h2>
                <p>A welcoming space designed for comfort, safety, and convenience.</p>
            </div>
        </div>

        <div class="slide">
            <img src="<?= h(BASE_URL) ?>/reservations/uploads/dorm-2.jpg"
                 alt="Dormitory room">
            <div class="slide-overlay"></div>
            <div class="slide-caption">
                <h2>Comfortable Living Spaces</h2>
                <p>Enjoy a clean and organized environment during your stay.</p>
            </div>
        </div>

        <div class="slide">
            <img src="<?= h(BASE_URL) ?>/reservations/uploads/dorm-3.jpg"
                 alt="Dormitory common area">
            <div class="slide-overlay"></div>
            <div class="slide-caption">
                <h2>A Place to Feel at Home</h2>
                <p>Making everyday dormitory living more convenient.</p>
            </div>
        </div>

        <div class="slide-indicators">
            <button class="indicator active" type="button" aria-label="Slide 1"></button>
            <button class="indicator" type="button" aria-label="Slide 2"></button>
            <button class="indicator" type="button" aria-label="Slide 3"></button>
        </div>

        <div class="slide-controls">
            <button type="button" id="previousSlide" aria-label="Previous image">‹</button>
            <button type="button" id="nextSlide" aria-label="Next image">›</button>
        </div>

    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

// SHOW / HIDE PASSWORD

document.getElementById('togglePassword').addEventListener('click', function () {

    const passwordInput = document.getElementById('password');
    const isHidden = passwordInput.type === 'password';

    passwordInput.type = isHidden ? 'text' : 'password';
    this.textContent = isHidden ? 'Hide' : 'Show';

    this.setAttribute(
        'aria-label',
        isHidden ? 'Hide password' : 'Show password'
    );

    this.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
});


// AUTOMATIC IMAGE SLIDESHOW

const slides = document.querySelectorAll('.slide');
const indicators = document.querySelectorAll('.indicator');

let currentSlide = 0;
let slideshowTimer;

function showSlide(index) {

    currentSlide = (index + slides.length) % slides.length;

    slides.forEach((slide, i) => {
        slide.classList.toggle('active', i === currentSlide);
    });

    indicators.forEach((indicator, i) => {
        indicator.classList.toggle('active', i === currentSlide);
    });
}

function nextSlide() {
    showSlide(currentSlide + 1);
}

function previousSlide() {
    showSlide(currentSlide - 1);
}

function startSlideshow() {
    clearInterval(slideshowTimer);
    slideshowTimer = setInterval(nextSlide, 5000);
}

document.getElementById('nextSlide').addEventListener('click', () => {
    nextSlide();
    startSlideshow();
});

document.getElementById('previousSlide').addEventListener('click', () => {
    previousSlide();
    startSlideshow();
});

indicators.forEach((indicator, index) => {
    indicator.addEventListener('click', () => {
        showSlide(index);
        startSlideshow();
    });
});

startSlideshow();


// SUCCESS MESSAGE

<?php if ($flashMessage): ?>

Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($flashMessage['message'] ?? 'Account created.') ?>
});

<?php endif; ?>


// LOGIN ERROR MESSAGE

<?php if ($error !== ''): ?>

Swal.fire({
    icon: 'error',
    title: 'Sign in failed',
    text: <?= json_encode($error) ?>
});

<?php endif; ?>

</script>

</body>
</html>
