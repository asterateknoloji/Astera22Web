using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Input;
using AsteraSoftPhone.Core;

namespace AsteraSoftPhone.ViewModels;

public sealed class MainViewModel : INotifyPropertyChanged
{
    private readonly ISipService _sipService;
    private RegistrationState _registrationState = RegistrationState.Disconnected;
    private string _statusDetail = "Hazır";
    private int _statusCode;
    private bool _isBusy;

    public MainViewModel(ISipService sipService, SipOptions options)
    {
        _sipService = sipService;
        Account = options.Username;
        Server = $"{options.Server}:{options.Port}";
        Transport = options.Transport.ToUpperInvariant();
        ConnectCommand = new AsyncCommand(ConnectAsync, () => !IsBusy);
        DisconnectCommand = new AsyncCommand(DisconnectAsync, () => !IsBusy);
        _sipService.RegistrationStateChanged += OnRegistrationStateChanged;
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string Account { get; }
    public string Server { get; }
    public string Transport { get; }
    public ICommand ConnectCommand { get; }
    public ICommand DisconnectCommand { get; }

    public string StatusText => RegistrationState switch
    {
        RegistrationState.Connecting => "Connecting",
        RegistrationState.Registering => "Registering",
        RegistrationState.Registered => "Registered",
        RegistrationState.RegistrationFailed => "Registration Failed",
        _ => "Disconnected"
    };

    public string StatusColor => RegistrationState switch
    {
        RegistrationState.Registered => "#2CB67D",
        RegistrationState.Connecting or RegistrationState.Registering => "#F4B740",
        RegistrationState.RegistrationFailed => "#EF5B5B",
        _ => "#8B98AA"
    };

    public RegistrationState RegistrationState
    {
        get => _registrationState;
        private set
        {
            if (!SetField(ref _registrationState, value))
                return;

            OnPropertyChanged(nameof(StatusText));
            OnPropertyChanged(nameof(StatusColor));
        }
    }

    public string StatusDetail
    {
        get => _statusDetail;
        private set => SetField(ref _statusDetail, value);
    }

    public string StatusCode => _statusCode == 0 ? "—" : _statusCode.ToString();

    public bool IsBusy
    {
        get => _isBusy;
        private set
        {
            if (SetField(ref _isBusy, value))
                CommandManager.InvalidateRequerySuggested();
        }
    }

    public Task StartAsync() => ConnectAsync();

    private async Task ConnectAsync()
    {
        if (IsBusy)
            return;

        IsBusy = true;
        try
        {
            if (_sipService.RegistrationState != RegistrationState.Disconnected)
                await _sipService.StopAsync();

            await _sipService.StartAsync();
        }
        finally
        {
            IsBusy = false;
        }
    }

    private async Task DisconnectAsync()
    {
        if (IsBusy)
            return;

        IsBusy = true;
        try
        {
            await _sipService.StopAsync();
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void OnRegistrationStateChanged(
        object? sender,
        RegistrationStateChangedEventArgs args)
    {
        Application.Current.Dispatcher.InvokeAsync(() =>
        {
            RegistrationState = args.State;
            StatusDetail = args.Detail;
            _statusCode = args.StatusCode;
            OnPropertyChanged(nameof(StatusCode));
        });
    }

    private bool SetField<T>(ref T field, T value, [CallerMemberName] string? name = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
            return false;

        field = value;
        OnPropertyChanged(name);
        return true;
    }

    private void OnPropertyChanged([CallerMemberName] string? name = null) =>
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(name));

    private sealed class AsyncCommand(
        Func<Task> execute,
        Func<bool> canExecute) : ICommand
    {
        public event EventHandler? CanExecuteChanged
        {
            add => CommandManager.RequerySuggested += value;
            remove => CommandManager.RequerySuggested -= value;
        }

        public bool CanExecute(object? parameter) => canExecute();

        public async void Execute(object? parameter)
        {
            try
            {
                await execute();
            }
            catch
            {
                // Service converts operational errors into registration state updates.
            }
        }
    }
}
