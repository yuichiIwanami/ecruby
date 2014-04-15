'use strict';

var ordersDetail = angular.module('ordersDetail', []).
    controller('ordersDetailCtrl', function($scope) {
        $scope.products = [
            {id: "001", name: "book", price: 1000, quantity: 1, tax:80}
        ];
        $scope.test;
    });
