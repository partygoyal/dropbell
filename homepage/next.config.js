module.exports = {
  output: 'standalone',
  poweredByHeader: false,
  async rewrites() {
    return process.env.NODE_ENV === 'development' ? [{ source: '/vendor/:path*', destination: 'http://127.0.0.1:8000/vendor/:path*' }] : [];
  },
  async headers() {
    return [{ source: '/:path*', headers: [
      { key: 'X-Content-Type-Options', value: 'nosniff' },
      { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
      { key: 'X-Frame-Options', value: 'DENY' },
    ] }];
  },
};
