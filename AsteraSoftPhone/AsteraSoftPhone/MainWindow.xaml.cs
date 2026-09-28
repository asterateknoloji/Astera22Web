using System.Windows;
using AsteraSoftPhone.ViewModels;

namespace AsteraSoftPhone;

public partial class MainWindow : Window
{
    public MainWindow(MainViewModel viewModel)
    {
        InitializeComponent();
        DataContext = viewModel;
    }
}