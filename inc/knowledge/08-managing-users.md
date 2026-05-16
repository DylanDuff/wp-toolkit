# Managing Users

User accounts control who can log in to the WordPress admin and what they can do. You can add accounts for new staff members and remove them when they leave — no developer needed.

---

## Access Levels

WordPress uses roles to control what each user can do. For most client sites, the relevant roles are:

| Role | What they can do |
|---|---|
| **Administrator** | Full access to everything — settings, plugins, users, and content |
| **Editor** | Can edit all pages and content, manage collections, and moderate comments. Cannot access settings or plugins |
| **Author** | Can write, edit, and publish their own posts only. Cannot edit other users' content |
| **Contributor** | Can write and edit their own posts but cannot publish — content must be approved by an Editor or Administrator |
| **Subscriber** | Can only manage their own profile. No access to content or admin features |

Your account is set up as an **Editor**, which gives you everything you need to manage day-to-day content without exposing settings and options that aren't relevant to content work. This keeps the admin clean and focused.

If you need Administrator access — for example, if you're taking over full management of the site yourself — just get in touch and we'll sort that for you.

---

## Adding a New User

1. Go to [**Users → Add New**](user-new.php) in the left-hand admin menu.
2. Fill in the following:
   - **Username** — this is used to log in and cannot be changed later
   - **Email** — WordPress will send their login details here
   - **First Name** and **Last Name**
   - **Role** — set to **Editor** for most staff members
3. Make sure **Send the new user an email about their account** is ticked.
4. Click **Add New User**.

The new user will receive an email with a link to set their own password.

---

## Changing a User's Password

If a user is locked out or needs their password reset:

1. Go to [**Users**](users.php) and click on their name.
2. Scroll down to the **Account Management** section.
3. Click **Send Reset Link** — this emails them a password reset link directly.

Alternatively, they can use the **Lost your password?** link on the login page themselves.

---

## Editing a User

1. Go to [**Users**](users.php) and click on the user's name.
2. Update their details as needed — name, email, or role.
3. Scroll to the bottom and click **Update User**.

---

## Removing a User

1. Go to [**Users**](users.php) and hover over the user's name.
2. Click **Delete**.
3. WordPress will ask what to do with any content they authored — select **Attribute all content to** and choose another user (such as yourself) to keep their content intact.
4. Click **Confirm Deletion**.

> Deleting a user does not delete the pages or posts they created — as long as you reassign their content during deletion.

---

## Keeping Access Secure

- Remove accounts promptly when a staff member leaves
- Avoid sharing a single login between multiple people — each person should have their own account
- If you suspect an account has been compromised, contact your developer immediately
