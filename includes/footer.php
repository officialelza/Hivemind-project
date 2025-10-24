</div> <!-- Close .container -->
<?php
// includes/footer.php — safe, compact footer role check
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


$role = $_SESSION['role'] ?? '';
?>
<footer class="text-center mt-5 border-top pt-3">
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>🐝 HiveMind</h5>
                    <p>Empowering learners worldwide through collaborative skill sharing and expert mentorship.</p>
                    <div class="social-links">
                        <a href="https://www.facebook.com/"><i class="fab fa-facebook-f"></i></a>
                        <a href="https://twitter.com/"><i class="fab fa-twitter"></i></a>
                        <a href="https://www.instagram.com/"><i class="fab fa-instagram"></i></a>
                        <a href="https://www.linkedin.com/"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>Platform</h5>
                    <ul>
                        <?php if ($role === 'expert'): ?>
                            <li><a href="pages/browse.php">Browse Skills</a></li>
                            <li><a href="pages/submit_skill.php">Submit a Skill</a></li>
                        <?php elseif ($role === 'learner'): ?>
                            <li><a href="pages/browse.php">Browse Skills</a></li>
                            <li><a href="pages/my_learning.php">My Learning</a></li>
                        <?php elseif ($role === 'admin'): ?>
                            <li><a href="../admin/a_dashboard.php">Admin Dashboard</a></li>
                            <li><a href="../admin/a_dashboard.php">Manage Users</a></li>
                        <?php else: ?>
                            <li><a href="pages/browse.php">Browse Skills</a></li>
                            <li><a href="auth/register.php">Become an Expert</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>Company</h5>
                    <ul>
                        <li><a href="../info/about.php">About Us</a></li>
                        <li><a href="">Press</a></li>
                        <li><a href="#">Blog</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <h5>Support</h5>
                    <ul>
                        <li><a href="#">Help Center</a></li>
                        <li><a href="../info/contact.php">Contact Us</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
            <hr class="my-4">
            <div class="row">
                <div class="col-md-8">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> HiveMind. All rights reserved.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="mb-0">
                        <a href="#" class="text-decoration-none me-3">Privacy</a>
                        <a href="#" class="text-decoration-none">Terms</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>



    <!-- Optional Bootstrap Bundle (for navbar toggle) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>