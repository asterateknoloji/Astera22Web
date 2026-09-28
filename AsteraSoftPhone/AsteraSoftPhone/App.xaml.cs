using System.Windows;
using AsteraSoftPhone.Core;
using AsteraSoftPhone.Infrastructure;
using AsteraSoftPhone.Sip;
using AsteraSoftPhone.ViewModels;

namespace AsteraSoftPhone;

public partial class App : Application
{
    private FileAppLogger? _logger;
    private ISipService? _sipService;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        try
        {
            var applicationDirectory = AppContext.BaseDirectory;
            _logger = new FileAppLogger(applicationDirectory);
            var options = SipConfigurationLoader.Load(applicationDirectory);
            _sipService = new PjsipService(options, _logger, applicationDirectory);

            var viewModel = new MainViewModel(_sipService, options);
            var mainWindow = new MainWindow(viewModel);
            MainWindow = mainWindow;
            mainWindow.Show();

            await viewModel.StartAsync();
        }
        catch (Exception exception)
        {
            _logger?.Error("ERROR", "Application startup failed.", exception);
            MessageBox.Show(
                exception.Message,
                "ASTERA Softphone",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            Shutdown(1);
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        if (_sipService is not null)
        {
            try
            {
                _sipService.DisposeAsync().AsTask().GetAwaiter().GetResult();
            }
            catch (Exception exception)
            {
                _logger?.Error("ERROR", "Application shutdown failed.", exception);
            }
        }

        _logger?.Dispose();
        base.OnExit(e);
    }
}

