const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // Dossier de sortie
    .setOutputPath('public/build/')
    .setPublicPath('/build')

    // Fichiers d'entrée
    .addEntry('app', './assets/app.js')
    .addEntry('admin', './assets/admin.js')
    .addEntry('company', './assets/company.js')
    .addEntry('service-client', './assets/service-client.js')

    // CSS
    .addStyleEntry('admin-styles', './assets/styles/admin.scss')
    .addStyleEntry('company-styles', './assets/styles/company.scss')
    .addStyleEntry('service-client-styles', './assets/styles/service-client.scss')

    // Split chunks
    .splitEntryChunks()
    .enableSingleRuntimeChunk()

    // Features
    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    // Sass/SCSS
    .enableSassLoader()

    // PostCSS
    .enablePostCssLoader()

    // jQuery
    .autoProvidejQuery()
;

module.exports = Encore.getWebpackConfig();