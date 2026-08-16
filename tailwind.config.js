/** Config de build usada solo por mantenedores para regenerar assets/css/appycrud.css.
 *  Los usuarios finales de appycrud NO necesitan Node ni esta config: el CSS ya
 *  viene precompilado en el repo. Ver docs/desarrollo.md para regenerarlo. */
module.exports = {
  content: ['./src/**/*.php', './examples/**/*.php'],
  theme: {
    extend: {},
  },
  plugins: [],
};
