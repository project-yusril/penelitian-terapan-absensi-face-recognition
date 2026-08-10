import 'package:flutter_bloc/flutter_bloc.dart';
import '../../domain/repositories/sp_repository.dart';
import 'sp_event.dart';
import 'sp_state.dart';

class SpBloc extends Bloc<SpEvent, SpState> {
  final SpRepository _repository;

  SpBloc(this._repository) : super(SpInitial()) {
    on<LoadMySpRecords>(_onLoadMySpRecords);
    on<RefreshMySpRecords>(_onRefreshMySpRecords);
  }

  Future<void> _onLoadMySpRecords(
    LoadMySpRecords event,
    Emitter<SpState> emit,
  ) async {
    emit(SpLoading());
    await _loadRecords(emit);
  }

  Future<void> _onRefreshMySpRecords(
    RefreshMySpRecords event,
    Emitter<SpState> emit,
  ) async {
    await _loadRecords(emit);
  }

  Future<void> _loadRecords(Emitter<SpState> emit) async {
    final result = await _repository.getMySpRecords();
    result.fold(
      (failure) => emit(SpError(failure.message)),
      (records) => emit(SpLoaded(records: records)),
    );
  }
}
