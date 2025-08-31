<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>New User Registration Pending Approval</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #1f2937;">

  <!-- NAVBAR -->
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#ab2328; text-align: center; padding: 16px 0;">
    <tr>
      <td>
        <span style="color: white; font-size: 24px; font-weight: bold;">DORDIELIST</span>
      </td>
    </tr>
  </table>

  <!-- MAIN CONTENT WRAPPER -->
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 40px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background-color: white; border-radius: 8px; padding: 32px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
          <tr>
            <td>
              <h2 style="font-size: 20px; font-weight: bold; margin-bottom: 16px;">New User Registration Pending Approval</h2>

              <p style="font-weight: 500; margin-bottom: 8px;">A new user has just registered with the following details:</p>

              <table align="center" role="presentation" style="margin: 16px auto 24px auto; text-align: left;">
                <tr>
                  <td style="font-weight: 500;"><strong>Username:</strong> {{ $username }}</td>
                </tr>
                <tr>
                  <td style="font-weight: 500;"><strong>Email:</strong> {{ $emailAddr }}</td>
                </tr>
              </table>

              <p style="margin-bottom: 24px;">Please click the button below to activate this user’s account. Once you confirm, their status in the database will become active, and they will be able to log in immediately.</p>

              <a href="{{ $confirmUrl }}" style="
                display: inline-block;
                padding: 12px 24px;
                background-color: #08875b;
                color: white;
                text-decoration: none;
                font-weight: bold;
                border-radius: 4px;
              ">
                Confirm & Activate User
              </a>

              <p style="margin-top: 32px; font-size: 14px; color: #6b7280;">
                If you did not expect this registration, simply ignore this email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

<!-- FOOTER -->
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 40px; background-color: #f3f4f6; font-family: Arial, sans-serif;">
  <tr>
    <td align="center">
      <!-- Divider -->
      <hr style="border: none; border-top: 1px solid #d1d5db; width: 90%; margin: 0 auto;" />

      <!-- Footer Content -->
      <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px auto; text-align: center; font-size: 14px; color: #6b7280;">
        <tr>
          <td style="padding-bottom: 12px;">
            &copy; DORDIELIST, LLC 2025 All rights reserved.
          </td>
        </tr>
        <tr>
          <td>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Contact</a>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Support</a>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Jobs</a>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Terms</a>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Privacy</a>
            <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Merch</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>


</body>
</html>
