import 'package:flutter_bloc/flutter_bloc.dart';
import '../../domain/repositories/leave_repository.dart';
import 'leave_event.dart';
import 'leave_state.dart';

class LeaveBloc extends Bloc<LeaveEvent, LeaveState> {
  final LeaveRepository _repository;

  LeaveBloc({required LeaveRepository repository})
    : _repository = repository,
      super(LeaveInitial()) {
    on<LoadMyLeaves>(_onLoadMyLeaves);
    on<SubmitLeave>(_onSubmitLeave);
  }

  Future<void> _onLoadMyLeaves(
    LoadMyLeaves event,
    Emitter<LeaveState> emit,
  ) async {
    emit(LeaveLoading());
    final result = await _repository.getMyLeaves();
    result.fold(
      (failure) => emit(LeaveError(failure.message)),
      (leaves) => emit(LeaveLoaded(leaves)),
    );
  }

  Future<void> _onSubmitLeave(
    SubmitLeave event,
    Emitter<LeaveState> emit,
  ) async {
    emit(LeaveLoading());
    final result = await _repository.submitLeave(
      jenis: event.jenis,
      mataKuliahId: event.mataKuliahId,
      tanggalMulai: event.tanggalMulai,
      tanggalSelesai: event.tanggalSelesai,
      keterangan: event.keterangan,
      filePath: event.filePath,
    );
    result.fold(
      (failure) => emit(LeaveError(failure.message)),
      (leave) => emit(LeaveSubmitted(leave)),
    );
  }
}
