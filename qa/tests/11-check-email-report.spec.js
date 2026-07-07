import { test, expect } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';
import { google } from 'googleapis';

dotenv.config({ path: path.resolve(import.meta.dirname, '.env.qa') });

const CLIENT_ID = process.env.GMAIL_CLIENT_ID;
const TWILIO_SID = process.env.TWILIO_SID;

test('check gmail for lesson report email', async () => {
  // Get credentials from .env.claude.local equivalent
  const clientSecret = process.env.GMAIL_CLIENT_SECRET;
  const refreshToken = process.env.GMAIL_REFRESH_TOKEN;

  if (!clientSecret || !refreshToken) {
    console.log('SKIP: Missing Gmail credentials');
    return;
  }

  const oauth2Client = new google.auth.OAuth2(CLIENT_ID, clientSecret);
  oauth2Client.setCredentials({ refresh_token: refreshToken });

  const gmail = google.gmail({ version: 'v1', auth: oauth2Client });

  // Search for lesson report emails
  const resp = await gmail.users.messages.list({
    userId: 'me',
    q: 'subject:"reporte de aprendizaje" newer_than:1d',
    maxResults: 10,
  });

  const msgs = resp.data.messages || [];
  console.log(`Emails found with "reporte de aprendizaje": ${msgs.length}`);

  for (const m of msgs.slice(0, 3)) {
    const detail = await gmail.users.messages.get({ userId: 'me', id: m.id, format: 'metadata', metadataHeaders: ['Subject', 'To', 'Date'] });
    const headers = detail.data.payload.headers;
    const subj = headers.find(h => h.name === 'Subject')?.value;
    const to = headers.find(h => h.name === 'To')?.value;
    const date = headers.find(h => h.name === 'Date')?.value;
    console.log(`  Subject: ${subj}`);
    console.log(`  To: ${to}`);
    console.log(`  Date: ${date}`);
  }

  expect(msgs.length).toBeGreaterThan(0);
});
