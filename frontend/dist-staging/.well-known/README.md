# Apple Pay domain verification

Place the Apple Pay **domain-association file** in this directory so it is served at:

```
https://<your-domain>/.well-known/apple-developer-merchantid-domain-association
```

Steps:

1. Moyasar Dashboard → **Settings → Apple Pay → Web** → add your production domain.
2. Click **Download Association** and save the file here as exactly:

   ```
   frontend/public/.well-known/apple-developer-merchantid-domain-association
   ```

   No extension. Do **not** add `.txt` / `.data` — Apple rejects any extension.

3. Vite copies everything under `public/` into the build output, so the file is
   served from the SPA root. The dedicated nginx `location` block in
   `frontend/nginx.conf` returns it verbatim instead of the SPA `index.html`.
4. Back in the Moyasar Dashboard, click **Verify**. Once verified, the Apple Pay
   button renders automatically on Safari / Apple devices over HTTPS.

> The association file is environment/domain-specific — it is intentionally **not**
> committed. Add the real file during deployment.
