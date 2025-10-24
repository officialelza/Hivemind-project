<?php include '../includes/header.php'; ?>
<h1>Contact Us</h1>

<form action="mailto:hivemind.help@gmail.com" method="POST" enctype="text/plain">
  <label for="name">Your Name</label>
  <input type="text" id="name" name="name" required>

  <label for="email">Your Email</label>
  <input type="email" id="email" name="email" required>

  <label for="subject">Subject</label>
  <input type="text" id="subject" name="subject" required>

  <label for="message">Message</label>
  <textarea id="message" name="message" rows="6" required></textarea>

  <button type="submit">Send Message</button>
</form>

<div class="info mt-4">
  <p><strong>Email:</strong> hivemind.help@gmail.com</p>
  <p><strong>Phone:</strong> +91 98765 43210</p>
  <p><strong>Address:</strong> HiveMind HQ, Innovation Street, Tech Park, India</p>
</div>
<?php include '../includes/footer.php'; ?>