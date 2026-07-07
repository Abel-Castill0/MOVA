#!/usr/bin/env node
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const envPath = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '.env.qa');
const envLines = readFileSync(envPath, 'utf8').split('\n');
const env = Object.fromEntries(envLines.filter(l => l && !l.startsWith('#') && l.includes('=')).map(l => [l.split('=')[0].trim(), l.split('=').slice(1).join('=').trim()]));

const tokenRes = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: env.GMAIL_CLIENT_ID, client_secret: env.GMAIL_CLIENT_SECRET, refresh_token: env.GMAIL_READONLY_REFRESH_TOKEN, grant_type: 'refresh_token' }),
});
const { access_token } = await tokenRes.json();
if (!access_token) { console.log('Token failed'); process.exit(1); }

const searchRes = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages?q=${encodeURIComponent('subject:"Verify Email" newer_than:2d')}&maxResults=5`, { headers: { Authorization: `Bearer ${access_token}` } });
const { messages = [] } = await searchRes.json();
console.log(`Found ${messages.length} verification emails`);

function decodeBody(msg) {
    function tryPart(part) {
        if (!part) return null;
        if (part.body?.data) return Buffer.from(part.body.data, 'base64url').toString('utf-8');
        if (part.parts) { for (const p of part.parts) { const r = tryPart(p); if (r) return r; } }
        return null;
    }
    return tryPart(msg.payload) || '';
}

const links = [];
for (const m of messages) {
    const res = await fetch(`https://gmail.googleapis.com/gmail/v1/users/me/messages/${m.id}?format=full`, { headers: { Authorization: `Bearer ${access_token}` } });
    const msg = await res.json();
    const subject = msg.payload?.headers?.find(h => h.name === 'Subject')?.value || '';
    const to = msg.payload?.headers?.find(h => h.name === 'To')?.value || '';
    const body = decodeBody(msg);

    // Extract ALL URLs from the body
    const urlMatches = [...body.matchAll(/href="(https?:\/\/[^"]+)"/g)].map(m => m[1]);
    const verifyUrl = urlMatches.find(u => u.includes('verify') || u.includes('email'));

    console.log(`\nEmail: ${subject} | To: ${to}`);
    console.log(`URLs found: ${urlMatches.length}`);
    urlMatches.slice(0, 5).forEach(u => console.log('  URL:', u.substring(0, 100)));

    if (verifyUrl) {
        console.log('>> VERIFY URL:', verifyUrl.substring(0, 100));
        links.push({ to, link: verifyUrl });
    } else if (urlMatches.length > 0) {
        // Pick the first non-unsubscribe URL as likely verify link
        const candidate = urlMatches.find(u => !u.includes('unsubscribe') && !u.includes('privacy'));
        if (candidate) {
            console.log('>> CANDIDATE URL (may be verify):', candidate.substring(0, 100));
            links.push({ to, link: candidate });
        }
    }
}

if (links.length > 0) {
    writeFileSync('C:/Users/ABEL/AppData/Local/Temp/verify_links.json', JSON.stringify(links, null, 2));
    console.log('\nLinks saved:', links.length);
} else {
    console.log('\nNo links found — body may be plain text or different format');
}
