These five unmodified PHP files are the Mautic 7.2 native mail factory, invalid transport and three optional transport interfaces. They are derived from Mautic's GPL-3.0 licensed EmailBundle (`app/bundles/EmailBundle/Mailer/Transport`) and are included only to test the integration contract without installing or booting a Mautic application.

The test autoloader is a fallback: when tests run with an installed Mautic autoloader, the installation's actual classes take precedence. No model, kernel or database class is instantiated by these fixtures.
