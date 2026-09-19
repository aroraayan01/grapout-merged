/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false, // don't advertise Next.js
  // Served under a folder of another site — e.g. "/crm" on grapout.com, where
  // Apache proxies /crm to this app. Next bakes this into the build (every
  // asset URL, every <Link>), so it is a build arg, not a runtime setting;
  // blank means "own domain", which is how it has always run.
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || undefined,
  // NOTE: uploaded images are proxied by the route handler at app/media/assets/[id],
  // NOT a rewrite — Next bakes rewrite destinations into the build, which would
  // freeze the API URL at build time and ignore the runtime API_URL from compose.
  async headers() {
    const securityHeaders = [
      { key: 'X-Content-Type-Options', value: 'nosniff' },
      { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
      { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
      {
        key: 'Permissions-Policy',
        value: 'camera=(), microphone=(), geolocation=()',
      },
    ];
    if (process.env.NODE_ENV === 'production') {
      securityHeaders.push({
        key: 'Strict-Transport-Security',
        value: 'max-age=31536000; includeSubDomains',
      });
    }
    return [{ source: '/:path*', headers: securityHeaders }];
  },
};

export default nextConfig;
