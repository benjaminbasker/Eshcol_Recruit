# Connect resume@eshcolglobal.com to Eshcol Recruit

Once this is set up, CVs emailed to resume@ arrive in Eshcol Recruit within about 5 minutes. Each one is read, scored and put on the right role (from the code in the subject, e.g. **ADV-0001**). It is also sent to any other open role it fits, and the candidate gets the usual thank-you email. The app never moves or deletes anything in the mailbox. It only tags each handled email with the Outlook category **"In Eshcol Recruit"**.

There are three parts. Part A is about 5 minutes of clicking in Microsoft. In Part B, I type the commands and you only sign in. Part C is pasting the details into Eshcol Recruit.

---

## Before you start: deploy the new version

1. Upload `ATS/deploy/eshcol-ats-20261106-1557.zip` to `/home/benjaminbasker`, then extract it, overwriting existing files.
2. In Eshcol Recruit, go to **Settings › System › Apply updates** (1 update).
3. Purge Cloudflare.
4. Sign in again (the update signs everyone out once). On the code screen, tick **Trust this device for 7 days**. From then on, a sign-in on this computer lasts **24 hours**.

---

## Part A: register the app in Microsoft (you, about 5 minutes)

1. Go to **entra.microsoft.com** and sign in with your Microsoft 365 admin account.
2. Open **Identity › Applications › App registrations** and click **New registration**:
   - Name: **Eshcol Recruit CV inbox**
   - Supported account types: **Accounts in this organizational directory only**
   - Redirect URI: leave empty
   - Click **Register**.
3. On the **Overview** page that opens, note two values. Neither is secret, so you can send them to me in chat:
   - **Application (client) ID**
   - **Directory (tenant) ID**
4. Open **Certificates & secrets › Client secrets › New client secret**:
   - Description: **Eshcol Recruit**. Expires: **24 months**. Click **Add**.
   - Copy the **Value** straight away; Microsoft shows it only once. **Do not send it to me.** Save it in Datavault. You'll paste it into Eshcol Recruit yourself in Part C.
5. Open **Identity › Applications › Enterprise applications**, click **Eshcol Recruit CV inbox**, and note its **Object ID** (also not secret).

> **Do not add any API permissions here.** If you gave Microsoft's "Mail" permission in this screen, the app could read *every* mailbox in the company, including yours. Part B grants access to **resume@ only**.

## Part B: give the app access to resume@ only (I type, you sign in)

Send me the **Application (client) ID** and the **Object ID**. I'll run these commands in PowerShell on this computer. When a Microsoft sign-in window appears, sign in with your admin account.

```powershell
Install-Module ExchangeOnlineManagement -Scope CurrentUser
Connect-ExchangeOnline
Get-Recipient resume@eshcolglobal.com | Format-List RecipientTypeDetails
New-ServicePrincipal -AppId <client id> -ObjectId <object id> -DisplayName "Eshcol Recruit CV inbox"
New-ManagementScope -Name "Eshcol Recruit - resume mailbox" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'resume@eshcolglobal.com'"
New-ManagementRoleAssignment -App <client id> -Role "Application Mail.ReadWrite" -CustomResourceScope "Eshcol Recruit - resume mailbox"
Test-ServicePrincipalAuthorization -Identity <client id> -Resource resume@eshcolglobal.com
```

- The `Get-Recipient` line tells us what resume@ is. If it's a **UserMailbox** or **SharedMailbox**, we carry on. If it's only an **alias on your own mailbox**, we first turn it into a free **shared mailbox**, so the app never sees your own emails.
- The last line should show **InScope: True** for resume@. I'll also check that your own mailbox shows **False**.

## Part C: connect it in Eshcol Recruit (you, 1 minute)

1. Go to **Settings › CV inbox (resume@) › Connect resume@ (Microsoft 365)**.
2. Paste the **Directory (tenant) ID** and **Application (client) ID**, and paste the **client secret Value** from Datavault.
3. Mailbox: **resume@eshcolglobal.com**. "Bring in emails from": pick the date your new posting goes out, or leave it blank for the last 7 days.
4. Click **Save and test**. You should see "Connected to resume@eshcolglobal.com: N emails in the Inbox".
5. Click **Check now** to bring in what's already there. After that it runs by itself every 5 minutes.

## Then: post the jobs

On each role, open **⋯ › Post job**. The text asks applicants to email resume@eshcolglobal.com with **"ADV-0001 Accounting Associate"** (or **ADV-0002 Accounting Manager**) in the subject. Paste it into Indeed, LinkedIn and Naukri.

## Good to know

- **Settings › CV inbox** lists the last 15 emails, with a link to each candidate the app created.
- A CV with no code in the subject still comes in. Routing puts it on whichever role fits, or in a talent pool.
- If a CV has no email address, the sender's address is used, unless the email came from a job board's no-reply address.
- Sending the same CV twice is recognised, and the candidate isn't thanked twice.
- **The client secret expires in 24 months.** Make a new one in Part A, step 4, and paste it in Settings (the other fields stay as they are). Put a reminder in your calendar.
- To stop it: Settings › CV inbox › **Pause** or **Disconnect**.
