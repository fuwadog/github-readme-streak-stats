module.exports = {
  useTabs: false,
  printWidth: 120,
  endOfLine: "auto",
  plugins: ["@prettier/plugin-php"],
  overrides: [
    {
      files: "**/*.php",
      options: {
        parser: "php",
      },
    },
  ],
};
