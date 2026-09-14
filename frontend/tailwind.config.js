/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          blue: '#1554aa',
          dark: '#060b17',
          card: '#0c1529',
          border: '#1b2b4d',
          text: '#7088b3',
          subtext: '#486596',
        }
      }
    },
  },
  plugins: [],
};
