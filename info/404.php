<?php include '../includes/header.php'; ?>

<style>
    .error-container {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        background-color: var(--background-color);
        padding: 2rem;
        border-radius: 10px;
    }

    .error-image {
        flex: 1 1 45%;
        text-align: center;
    }

    .error-image img {
        max-width: 100%;
        height: auto;
    }

    .error-text {
        flex: 1 1 45%;
        text-align: center;
    }

    .error-text h1 {
        font-size: 6rem;
        font-weight: bold;
        color: var(--text-color);
    }

    .error-text h2 {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .error-text p {
        color: #666;
        font-size: 1rem;
        margin-bottom: 1.5rem;
    }

    .error-text a.btn {
        background-color: black;
        color: white;
        padding: 0.7rem 1.5rem;
        text-decoration: none;
        border-radius: 5px;
    }

    .error-text a.btn:hover {
        background-color: var(--primary-color);
    }

    @media (max-width: 768px) {
        .error-container {
            flex-direction: column;
        }
    }
</style>

<div class="error-container">
    <div class="error-image">
        <img src="../assets/images/404.png" alt="404 Illustration" />
    </div>

    <div class="error-text">
        <h1>404</h1>
        <h2>OOOps! Page Not Found</h2>
        <p>This page doesn’t exist or was removed.<br>We suggest going back to the homepage.</p>
        <a href="../index.html" class="btn">Back to homepage</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>