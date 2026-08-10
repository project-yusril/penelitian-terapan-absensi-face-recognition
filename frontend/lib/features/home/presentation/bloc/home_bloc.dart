import 'package:flutter_bloc/flutter_bloc.dart';
import '../../domain/entities/home_entities.dart';
import '../../domain/repositories/home_repository.dart';
import 'home_event.dart';
import 'home_state.dart';

class HomeBloc extends Bloc<HomeEvent, HomeState> {
  final HomeRepository _repository;

  HomeBloc(this._repository) : super(HomeInitial()) {
    on<LoadHomeData>(_onLoadHomeData);
    on<RefreshHomeData>(_onRefreshHomeData);
  }

  Future<void> _onLoadHomeData(
    LoadHomeData event,
    Emitter<HomeState> emit,
  ) async {
    emit(HomeLoading());
    await _loadData(emit);
  }

  Future<void> _onRefreshHomeData(
    RefreshHomeData event,
    Emitter<HomeState> emit,
  ) async {
    await _loadData(emit);
  }

  Future<void> _loadData(Emitter<HomeState> emit) async {
    final jadwalResult = await _repository.getTodaySchedule();
    final summaryResult = await _repository.getAttendanceSummary();
    final notifResult = await _repository.getRecentNotifications();

    final jadwal = jadwalResult.fold((_) => <JadwalHariIni>[], (data) => data);
    final summary = summaryResult.fold((_) => null, (data) => data);
    final notifs = notifResult.fold(
      (_) => <NotificationItem>[],
      (data) => data,
    );

    emit(
      HomeLoaded(jadwalList: jadwal, summary: summary, notifications: notifs),
    );
  }
}
